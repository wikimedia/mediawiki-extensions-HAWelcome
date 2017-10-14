<?php
/**
 * Highly Automated Welcome Tool -- welcomes new users after their first edit
 *
 * @file
 * @ingroup JobQueue
 * @author Krzysztof Krzyżaniak (eloy) <eloy@wikia-inc.com>
 * @author Maciej Błaszkowski (Marooned) <marooned at wikia-inc.com>
 * @author Jack Phoenix <jack@countervandalism.net>
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

	const WELCOMEUSER = 'ShoutWiki';

	/**
	 * Construct a job
	 *
	 * @param Title $title The title linked to
	 * @param array $params Job parameters (table, start and end page_ids)
	 * @param int $id job_id, 0 by default
	 */
	public function __construct( $title, $params, $id = 0 ) {
		parent::__construct( 'HAWelcome', $title, $params, $id );

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

		/**
		 * fallback
		 */
		if ( !$this->mUser ) {
			$this->mUser = User::newFromName( $this->mUserName );
		}
	}

	/**
	 * Main entry point
	 */
	public function run() {
		global $wgUser, $wgTitle, $wgLanguageCode;

		/**
		 * overwrite $wgUser for ~~~~ expanding
		 */
		$sysop = trim( wfMessage( 'welcome-user' )->plain() );
		if ( !in_array( $sysop, [ '@disabled', '-' ] ) ) {
			$tmpUser = $wgUser;
			$wgUser = User::newFromName( self::WELCOMEUSER );
			$flags = 0;
			if ( $wgUser && $wgUser->isBot() ) {
				$flags = EDIT_FORCE_BOT;
			}

			if ( $this->mUser && $this->mUser->getName() !== self::WELCOMEUSER && !$wgUser->isBlocked() ) {
				/**
				 * check again if talk page exists
				 */
				$talkPage = $this->mUser->getUserPage()->getTalkPage();

				if ( $talkPage ) {
					$this->mSysop = $this->getLastSysop();
					$tmpTitle     = $wgTitle;
					$sysopPage    = $this->mSysop->getUserPage()->getTalkPage();
					$signature    = $this->expandSig();

					$wgTitle     = $talkPage;
					$welcomeMsg  = false;
					$talkArticle = new Article( $talkPage, 0 );

					if ( !$talkArticle->exists() ) {
						if ( $this->mAnon ) {
							if ( $this->isEnabled( 'message-anon' ) ) {
								$welcomeMsg = wfMessage(
									'welcome-message-anon'
								)->inLanguage( $wgLanguageCode )->params(
									$this->getPrefixedText(),
									$sysopPage->getPrefixedText(),
									$signature,
									wfEscapeWikiText( $this->mUser->getName() )
								)->text();
							}
						} else {
							/**
							 * now create user page (if it doesn't exist already, of course)
							 */
							if ( $this->isEnabled( 'page-user' ) ) {
								$userPage = $this->getUserPage();
								if ( $userPage ) {
									$wgTitle = $userPage;
									$userArticle = new Article( $userPage, 0 );
									if ( !$userArticle->exists() ) {
										$pageMsg = wfMessage( 'welcome-user-page' )->inContentLanguage()->text();
										$content = ContentHandler::makeContent( $pageMsg, $userPage );
										$userArticle->doEditContent( $content, '', $flags );
									}
								}
							}

							if ( $this->isEnabled( 'message-user' ) ) {
								$welcomeMsg = wfMessage(
									'welcome-message-user'
								)->inLanguage( $wgLanguageCode )->params(
									$this->getPrefixedText(),
									$sysopPage->getPrefixedText(),
									$signature,
									wfEscapeWikiText( $this->mUser->getName() )
								)->text();
							}
						}
						if ( $welcomeMsg ) {
							$wgTitle = $talkPage; /* is it necessary there? */
							$content = ContentHandler::makeContent( $welcomeMsg, $talkPage );
							$talkArticle->doEditContent(
								$content,
								wfMessage( 'welcome-message-log' )->inContentLanguage()->escaped(),
								$flags
							);
						}
					}
					$wgTitle = $tmpTitle;
				}
			}

			$wgUser = $tmpUser;
		}

		return true;
	}

	/**
	 * Get last active sysop for this wiki, use local user database
	 *
	 * @return User class instance
	 */
	public function getLastSysop() {
		global $wgMemc;

		/**
		 * maybe already loaded?
		 */
		if ( !$this->mSysop ) {
			$sysop = trim( wfMessage( 'welcome-user' )->plain() );
			if ( !in_array( $sysop, [ '@disabled', '-' ] ) ) {
				if ( in_array( $sysop, [ '@latest', '@sysop' ] ) ) {
					/**
					 * first: check memcached, maybe we have already stored id of sysop
					 */
					$sysopId = $wgMemc->get( wfMemcKey( 'last-sysop-id' ) );
					if ( $sysopId ) {
						$this->mSysop = User::newFromId( $sysopId );
					} else {
						/**
						 * second: check database, could be expensive for database
						 */
						$dbr = wfGetDB( DB_REPLICA );

						/**
						 * get all users which are sysops/sysops or staff
						 * but not bots
						 *
						 * @todo check $db->makeList( $array )
						 */
						// ShoutWiki patch begin
						// Tweaked code for SW compatibility
						// If we should fetch staff member names, then they'll
						// be fetched from global_user_groups table
						// @author Jack Phoenix <jack@shoutwiki.com>
						// @date October 13, 2009
						$groups = [ 'ug_group' => [ 'sysop', 'bot' ] ];
						$wantStaff = false;
						if ( $sysop !== '@sysop' ) {
							$wantStaff = true;
						}

						$bots   = [];
						$admins = [];
						$res = $dbr->select(
							[ 'user_groups' ],
							[ 'ug_user', 'ug_group' ],
							$dbr->makeList( $groups, LIST_OR ),
							__METHOD__
						);
						if ( $wantStaff ) {
							// If we should fetch staffers, fetch 'em from the
							// correct table
							$res2 = $dbr->select(
								[ 'global_user_groups' ],
								[ 'gug_user', 'gug_group' ],
								[ 'gug_group' => 'staff' ],
								__METHOD__
							);
							foreach ( $res2 as $row2 ) {
								$admins[] = $row2->gug_user;
							}
						}
						// End ShoutWiki patch
						foreach ( $res as $row ) {
							if ( $row->ug_group == 'bot' ) {
								$bots[] = $row->ug_user;
							} else {
								$admins[] = $row->ug_user;
							}
						}

						/**
						 * remove bots from admins
						 */
						$admins = [ 'rev_user' => array_unique( array_diff( $admins, $bots ) ) ];
						$row = $dbr->selectRow(
							'revision',
							[ 'rev_user', 'rev_user_text' ],
							[
								$dbr->makeList( $admins, LIST_OR ),
								'rev_timestamp > ' . $dbr->addQuotes( $dbr->timestamp( time() - 5184000 ) ) // 60 days ago (24*60*60*60)
							],
							__METHOD__,
							[ 'ORDER BY' => 'rev_timestamp DESC' ]
						);
						if ( $row && $row->rev_user ) {
							$this->mSysop = User::newFromId( $row->rev_user );
							$wgMemc->set( wfMemcKey( 'last-sysop-id' ), $row->rev_user, 86400 );
						}
					}
				} else {
					$this->mSysop = User::newFromName( $sysop );
				}
			}

			/**
			 * fallback, if still user is unknown we use welcome user
			 */
			if ( $this->mSysop instanceof User && $this->mSysop->getId() ) {
				wfDebugLog( 'HAWelcome', 'Found sysop: ' . $this->mSysop->getName() );
			} else {
				$this->mSysop = User::newFromName( self::WELCOMEUSER );
			}
		}

		return $this->mSysop;
	}

	/**
	 * Static method called as hook
	 *
	 * @param Revision $revision Revision object
	 * @param string $url URL to external object
	 * @param string $flags Flags for this revision
	 * @return bool True means process other hooks
	 */
	public static function revisionInsertComplete( &$revision, $url, $flags ) {
		global $wgRequest, $wgUser, $wgCommandLineMode, $wgMemc;

		/**
		 * Do not create job when DB is locked (rt#12229)
		 */
		if ( wfReadOnly() ) {
			return true;
		}

		/**
		 * Revision has valid Title field but sometimes not filled
		 */
		$title = $revision->getTitle();
		if ( !$title ) {
			$title = Title::newFromId( $revision->getPage(), Title::GAID_FOR_UPDATE );
			$revision->setTitle( $title );
		}

		/**
		 * get groups for user rt#12215
		 */
		$groups = $wgUser->getEffectiveGroups();
		$invalid = [
			'bot' => true,
			'staff' => true,
			'sysop' => true,
			'bureaucrat' => true
		];
		$canWelcome = true;
		foreach ( $groups as $group ) {
			if ( isset( $invalid[$group] ) && $invalid[$group] ) {
				$canWelcome = false;
				wfDebugLog( 'HAWelcome', "Skipping welcome since user is in $group group" );
				break;
			}
		}

		/**
		 * put possible welcomer into memcached, RT#14067
		 */
		if ( $wgUser->getId() && self::isWelcomer( $wgUser ) ) {
			$wgMemc->set( wfMemcKey( 'last-sysop-id' ), $wgUser->getId(), 86400 );
			wfDebugLog( 'HAWelcome', 'Storing possible welcomer in memcached' );
		}

		if ( $title && !$wgCommandLineMode && $canWelcome ) {
			$welcomer = trim( wfMessage( 'welcome-user' )->inContentLanguage()->plain() );

			if ( $welcomer !== '@disabled' && $welcomer !== '-' ) {
				/**
				 * check if talk page for wgUser exists
				 *
				 * @todo check editcount for user
				 */
				$talkPage = $wgUser->getUserPage()->getTalkPage();
				if ( $talkPage ) {
					$talkArticle = new Article( $talkPage, 0 );
					if ( !$talkArticle->exists() ) {
						$welcomeJob = new HAWelcomeJob(
							$title,
							[
								'is_anon'   => $wgUser->isAnon(),
								'user_id'   => $wgUser->getId(),
								'user_ip'   => $wgRequest->getIP(),
								'user_name' => $wgUser->getName(),
							]
						);
						$welcomeJob->insert();
					}
				}
			}
		}

		return true;
	}

	/**
	 * HACK, expand signature from message for sysop
	 */
	private function expandSig() {
		global $wgContLang, $wgUser;

		$this->mSysop = $this->getLastSysop();
		$tmpUser = $wgUser;
		$wgUser = $this->mSysop;
		$sysopName = wfEscapeWikiText( $this->mSysop->getName() );
		$signature = sprintf(
			'-- [[%s:%s|%s]] ([[%s:%s|%s]]) %s',
			$wgContLang->getNsText( NS_USER ),
			$sysopName,
			$sysopName,
			$wgContLang->getNsText( NS_USER_TALK ),
			$sysopName,
			wfMessage( 'talkpagelinktext' )->inContentLanguage()->text(),
			$wgContLang->timeanddate( wfTimestampNow( TS_MW ) )
		);
		$wgUser = $tmpUser;

		return $signature;
	}

	/**
	 * @return Title instance of Title object
	 */
	public function getTitle() {
		return $this->title;
	}

	/**
	 * @return Title instance of Title object
	 */
	public function getPrefixedText() {
		return $this->title->getPrefixedText();
	}

	/**
	 * Check if some (or all) functionality is disabled/enabled
	 *
	 * @param string|bool $what Default false, possible values: page-user, message-anon, message-user
	 * @return bool Disabled or not
	 */
	public function isEnabled( $what ) {
		$return = false;
		$message = wfMessage( 'welcome-enabled' )->inContentLanguage()->plain();
		if (
			in_array( $what, [ 'page-user', 'message-anon', 'message-user' ] ) &&
			strpos( $message, $what ) !== false
		)
		{
			$return	= true;
		}

		return $return;
	}

	/**
	 * Check if user can welcome other users
	 *
	 * @param User $user Instance of User class
	 * @return bool Status of the operation
	 */
	public static function isWelcomer( &$user ) {
		$sysop  = trim( wfMessage( 'welcome-user' )->plain() );
		$groups = $user->getEffectiveGroups();
		$result = false;

		/**
		 * bots can't welcome
		 */
		if ( !in_array( 'bot', $groups ) ) {
			if ( $sysop === '@sysop' ) {
				$result = in_array( 'sysop', $groups ) ? true : false;
			} else {
				$result = in_array( 'sysop', $groups ) || in_array( 'staff', $groups ) ? true : false;
			}
		}

		return $result;
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
					$profileData['user_page_type'] === 1
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
