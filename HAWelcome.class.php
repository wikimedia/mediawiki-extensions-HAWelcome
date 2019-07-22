<?php
/**
 * Highly Automated Welcome Tool -- welcomes new users after their first edit
 *
 * @file
 * @ingroup JobQueue
 * @author Krzysztof Krzyżaniak (eloy) <eloy@wikia-inc.com>
 * @author Maciej Błaszkowski (Marooned) <marooned at wikia-inc.com>
 * @author Jack Phoenix
 * @date 2009-12-27 (r8975)
 * @copyright Copyright © Krzysztof Krzyżaniak for Wikia Inc.
 * @license http://www.gnu.org/copyleft/gpl.html GNU General Public License 2.0 or later
 */
class HAWelcomeJob extends Job {

	private
		$mUserId,
		$mUserName,
		$mUserIP,
		$mUser,
		$mAnon,
		$mSysop;

	/**
	 * Construct a job
	 *
	 * @param Title $title The title linked to
	 * @param array $params Job parameters (table, start and end page_ids)
	 */
	public function __construct( $title, $params ) {
		parent::__construct( 'HAWelcome', $title, $params );

		$this->mUserId   = $params['user_id'];
		$this->mUserIP   = $params['user_ip'];
		$this->mUserName = $params['user_name'];
		$this->mAnon     = (bool)$params['is_anon'];
		$this->mSysop    = false;

		if ( $this->mAnon ) {
			$this->mUser = User::newFromName( $this->mUserIP, false );
		} else {
			$this->mUser = User::newFromId( $this->mUserId );
		}

		// Fallback
		if ( !$this->mUser ) {
			$this->mUser = User::newFromName( $this->mUserName );
		}
	}

	/**
	 * Main entry point
	 */
	public function run() {
		global $wgLanguageCode, $wgHAWelcomeWelcomeUsername;

		$sysop = trim( wfMessage( 'welcome-user' )->plain() );
		if ( in_array( $sysop, [ '@disabled', '-' ] ) ) {
			return true;
		}

		$welcomeUser = User::newFromName( $wgHAWelcomeWelcomeUsername );
		$flags = 0;
		if ( $welcomeUser && $welcomeUser->isBot() ) {
			$flags = EDIT_FORCE_BOT;
		}

		if ( $this->mUser && $this->mUser->getName() !== $wgHAWelcomeWelcomeUsername && !$welcomeUser->isBlocked() ) {
			// Check again if talk page exists
			$talkPage = $this->mUser->getUserPage()->getTalkPage();

			if ( $talkPage ) {
				$this->mSysop = $this->getLastSysop();
				$sysopTalkPage = $this->mSysop->getUserPage()->getTalkPage();
				$signature = $this->expandSig();

				$welcomeMsg = false;
				$talkWikiPage = WikiPage::factory( $talkPage );

				if ( !$talkWikiPage->exists() ) {
					if ( $this->mAnon ) {
						if ( $this->isEnabled( 'message-anon' ) ) {
							$welcomeMsg = wfMessage(
								'welcome-message-anon'
							)->inLanguage( $wgLanguageCode )->params(
								$this->getPrefixedText(),
								$sysopTalkPage->getPrefixedText(),
								$signature,
								wfEscapeWikiText( $this->mUser->getName() )
							)->text();
						}
					} else {
						// Now create user page (if it doesn't exist already, of course)
						if ( $this->isEnabled( 'page-user' ) ) {
							$userPage = $this->getUserPage();
							if ( $userPage ) {
								$userWikiPage = WikiPage::factory( $userPage );

								if ( !$userWikiPage->exists() ) {
									$pageMsg = wfMessage( 'welcome-user-page' )->inContentLanguage()->text();
									$content = ContentHandler::makeContent( $pageMsg, $userPage );
									$userWikiPage->doEditContent(
										$content,
										'',
										$flags,
										0,
										$welcomeUser
									);
								}
							}
						}

						if ( $this->isEnabled( 'message-user' ) ) {
							$welcomeMsg = wfMessage(
								'welcome-message-user'
							)->inLanguage( $wgLanguageCode )->params(
								$this->getPrefixedText(),
								$sysopTalkPage->getPrefixedText(),
								$signature,
								wfEscapeWikiText( $this->mUser->getName() )
							)->text();
						}
					}

					if ( $welcomeMsg ) {
						$content = ContentHandler::makeContent( $welcomeMsg, $talkPage );
						$talkWikiPage->doEditContent(
							$content,
							wfMessage( 'welcome-message-log' )->inContentLanguage()->escaped(),
							$flags,
							0,
							$welcomeUser
						);
					}
				}

				$msgObj = wfMessage( 'user-board-welcome-message' )->inContentLanguage();
				// Send a welcome message on UserBoard provided it's installed and enabled
				if (
					class_exists( 'UserBoard' ) &&
					$this->isEnabled( 'board-welcome' ) &&
					!$msgObj->isDisabled()
				) {
					// Send the message
					$board = new UserBoard();
					$board->sendBoardMessage(
						$this->mSysop->getId(), // sender's UID
						$this->mSysop->getName(), // sender's name
						$this->mUser->getId(),
						$this->mUser->getName(),
						// passing the senderName as an argument here so that we can do
						// stuff like [[User talk:$1|contact me]] or w/e in the message
						$msgObj->params( $this->mSysop->getName() )->text()
						// the final argument is message type: 0 (default) for public
					);
				}
			}
		}

		return true;
	}

	/**
	 * Get last active sysop for this wiki, use local user database
	 *
	 * @return User class instance
	 */
	public function getLastSysop() {
		global $wgActorTableSchemaMigrationStage, $wgMemc, $wgHAWelcomeWelcomeUsername, $wgHAWelcomeStaffGroupName;

		// Maybe already loaded?
		if ( !$this->mSysop ) {
			$sysop = trim( wfMessage( 'welcome-user' )->plain() );
			if ( !in_array( $sysop, [ '@disabled', '-' ] ) ) {
				if ( in_array( $sysop, [ '@latest', '@sysop' ] ) ) {
					// First: check memcached, maybe we have already stored id of sysop
					$sysopId = $wgMemc->get( $wgMemc->makeKey( 'last-sysop-id' ) );
					if ( $sysopId ) {
						$this->mSysop = User::newFromId( $sysopId );
					} else {
						// Second: check database, could be expensive for database
						$dbr = wfGetDB( DB_REPLICA );

						/**
						 * Get all users which are sysops/sysops or staff but not bots
						 *
						 * @todo check $db->makeList( $array )
						 */

						$groups = [ 'ug_group' => [ 'sysop', 'bot' ] ];

						$bots = [];
						$admins = [];
						$res = $dbr->select(
							'user_groups',
							UserGroupMembership::selectFields(),
							$dbr->makeList( $groups, LIST_OR ),
							__METHOD__
						);

						foreach ( $res as $row ) {
							$ugm = UserGroupMembership::newFromRow( $row );
							if ( !$ugm->isExpired() ) {
								if ( $ugm->getGroup() === 'bot' ) {
									$bots[] = $ugm->getUserId();
								} else {
									$admins[] = $ugm->getUserId();
								}
							}
						}

						// ShoutWiki patch begin
						// Tweaked code for SW compatibility
						// If we should fetch staff member names, then they'll
						// be fetched from global_user_groups table
						// However, we should only do so when the GlobalUserrights extension is
						// installed.
						// @author Jack Phoenix <jack@shoutwiki.com>
						// @date October 13, 2009
						$wantStaff = $sysop !== '@sysop' && ExtensionRegistry::getInstance()->isLoaded( 'GlobalUserrights' );
						$staff = [];

						if ( $wantStaff ) {
							// If we should fetch staffers, fetch 'em from the correct table
							$res2 = $dbr->select(
								'global_user_groups',
								GlobalUserGroupMembership::selectFields(),
								[ 'gug_group' => $wgHAWelcomeStaffGroupName ],
								__METHOD__
							);

							$lookup = CentralIdLookup::factory();

							foreach ( $res2 as $row2 ) {
								$gugm = GlobalUserGroupMembership::newFromRow( $row2 );
								if ( !$gugm->isExpired() ) {
									// Get the local user id, since GlobalUserrights stores
									// central ID's. This is a two step process, because you can't
									// get a local ID directly from a central Id.
									$staffMember = $lookup->localUserFromCentralId( $gugm->getUserId() );
									$staff[] = $staffMember->getId();
								}
							}
						}

						// Merge arrays - Add the staff members to the list of potential welcomers
						$admins += $staff;

						// End ShoutWiki patch

						// Remove bots from admins.
						// Some bots also have administrator privileges, but since they are not
						// real users, they shouldn't be welcoming new users.
						$uniqueHumanAdmins = array_unique( array_diff( $admins, $bots ) );

						// Get the sysop who was active last
						if ( $wgActorTableSchemaMigrationStage & SCHEMA_COMPAT_READ_NEW ) {
							$revQuery = MediaWiki\MediaWikiServices::getInstance()->getRevisionStore()->getQueryInfo();
							$actorIds = [];
							// Convert user IDs to actor IDs because new tables don't care about UIDs
							foreach ( $uniqueHumanAdmins as $uniqueHumanAdmin ) {
								$user = User::newFromId( $uniqueHumanAdmin );
								if ( $user ) {
									$actorIds[] = $user->getActorId();
								}
							}
							$admins = [
								'revactor_actor' => $actorIds
							];
							$row = $dbr->selectRow(
								$revQuery['tables'],
								$revQuery['fields'],
								[
									$dbr->makeList( $admins, LIST_OR ),
									'revactor_timestamp > ' .
										$dbr->addQuotes( $dbr->timestamp( time() - 5184000 ) ) // 60 days ago (24*60*60*60)
								],
								__METHOD__,
								[ 'ORDER BY' => 'revactor_timestamp DESC' ],
								$revQuery['joins']
							);
						} else {
							$admins = [ 'rev_user' => $uniqueHumanAdmins ];
							$row = $dbr->selectRow(
								'revision',
								[ 'rev_user', 'rev_user_text' ],
								[
									$dbr->makeList( $admins, LIST_OR ),
									'rev_timestamp > ' .
										$dbr->addQuotes( $dbr->timestamp( time() - 5184000 ) ) // 60 days ago (24*60*60*60)
								],
								__METHOD__,
								[ 'ORDER BY' => 'rev_timestamp DESC' ]
							);
						}

						if ( $row && $row->rev_user ) {
							$this->mSysop = User::newFromId( $row->rev_user );
							$wgMemc->set( $wgMemc->makeKey( 'last-sysop-id' ), $row->rev_user, 86400 );
						} elseif ( $wantStaff ) {
							$staffCount = count( $staff );
							// Pick a random staff member so no-one gets left out
							$index = mt_rand( 0, $staffCount - 1 );
							$this->mSysop = User::newFromId( $staffCount[$index] );
							$wgMemc->set( $wgMemc->makeKey( 'last-sysop-id' ), $staffCount[$index], 86400 );
						}
					}
				} else {
					$this->mSysop = User::newFromName( $sysop );
				}
			}

			// Fallback, if the user is still unknown we use welcome user
			if ( $this->mSysop instanceof User && $this->mSysop->getId() ) {
				wfDebugLog( 'HAWelcome', 'Found sysop: ' . $this->mSysop->getName() );
			} else {
				$this->mSysop = User::newFromName( $wgHAWelcomeWelcomeUsername );
			}
		}

		return $this->mSysop;
	}

	/**
	 * Expand signature from a message or preference for sysop
	 * @return string
	 */
	private function expandSig() {
		global $wgContLang, $wgHAWelcomeSignatureFromPreferences;

		$this->mSysop = $this->getLastSysop();

		$sysopName = wfEscapeWikiText( $this->mSysop->getName() );
		$signature = wfMessage( 'signature' )->params( $sysopName, $sysopName )->plain();

		$signature = "-- $signature";

		if ( $wgHAWelcomeSignatureFromPreferences ) {
			// Nickname references to the preference that stores the custom signature
			$signature = $this->mSysop->getOption( 'nickname', $signature );
		}

		// Append timestamp
		$signature .= ' ' . $wgContLang->timeanddate( wfTimestampNow() );

		return $signature;
	}

	/**
	 * @return string the prefixed title with spaces
	 */
	public function getPrefixedText() {
		return $this->title->getPrefixedText();
	}

	/**
	 * Check if some (or all) functionality is disabled/enabled
	 *
	 * @param string|bool $what Default false, possible values: page-user, message-anon, message-user, board-welcome
	 * @return bool Disabled or not
	 */
	public function isEnabled( $what ) {
		$return = false;
		$message = wfMessage( 'welcome-enabled' )->inContentLanguage()->plain();

		$validValues = [
			'page-user',
			'message-anon',
			'message-user',
			'board-welcome'
		];

		if (
			in_array( $what, $validValues ) &&
			strpos( $message, $what ) !== false
		)
		{
			$return	= true;
		}

		return $return;
	}

	/**
	 * Get the Title object pointing to the wikitext user page of the user we're
	 * welcoming. On most wikis, this is the User: page, but on wikis with
	 * SocialProfile enabled, this can be the UserWiki: page, in which case the
	 * ordinary User: page (social profile, managed by the SocialProfile ext.)
	 * won't be editable.
	 *
	 * @return Title A valid Title object pointing to the specified user's
	 * wikitext user page
	 */
	private function getUserPage() {
		$userPage = $this->mUser->getUserPage();

		if ( class_exists( 'UserProfile' ) ) {
			// SocialProfile is installed -> the user
			// might've opted in for the social profile
			// to be their default User: page, so we can't
			// edit that and need to be editing their UserWiki:
			// page instead...let's figure that out.
			// This is somewhat c+p from UserProfile's
			// UserProfile.php, the ArticleFromTitle hook callback
			global $wgUserPageChoice;

			if ( $wgUserPageChoice ) {
				$profile = new UserProfile( $this->mUser->getName() );
				$profileData = $profile->getProfile();
				if (
					isset( $profileData['user_id'] ) &&
					$profileData['user_id'] &&
					// empty profile data can also mean brand new user account
					// (e.g. if you create a new account and trigger this code by making an
					// edit before filling out your profile data, profile data shows up as
					// empty, even the user_id, which definitely sounds like a bug, but anyway)
					$profileData['user_page_type'] === 1 || empty( $profileData['user_page_type'] )
				)
				{
					// SocialProfile as the User: page, wikitext user page
					// on the UserWiki: NS
					$userPage = Title::makeTitle( NS_USER_WIKI, $this->mUser->getName() );
				}
			}
		}

		return $userPage;
	}
}
