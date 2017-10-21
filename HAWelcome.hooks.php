<?php

class HAWelcomeHooks {
	/**
	 * Static method called as hook for RevisionInsertComplete
	 *
	 * @param WikiPage $article
	 * @param User $user
	 * @param Content $content
	 * @param string $summary
	 * @param bool $isMinor
	 * @param bool $isWatch (unused)
	 * @param $section (unused)
	 * @param int $flags Flags for this revision
	 * @param Revision $revision Revision object
	 * @param Status $status
	 * @param int|bool $baseRevId
	 * @param int $undidRevId
	 * @return bool True means process other hooks
	 */
	public static function onPageContentSaveComplete( WikiPage $article, User $user, Content $content, $summary, $isMinor, $isWatch, $section, $flags, Revision $revision, $status, $baseRevId, $undidRevId ) {
		global $wgCommandLineMode, $wgMemc;

		$request = RequestContext::getMain();

		// Do not create job when DB is locked (rt#12229)
		if ( wfReadOnly() ) {
			return true;
		}

		// Revision has valid Title field but sometimes not filled
		$title = $revision->getTitle();
		if ( !$title ) {
			$title = Title::newFromID( $revision->getPage(), Title::GAID_FOR_UPDATE );
			$revision->setTitle( $title );
		}

		// Get groups for user rt#12215
		$canWelcome = !$user->isAllowed( 'welcomeexempt' );
		if ( !$canWelcome ) {
			wfDebugLog( 'HAWelcome', 'Skipping welcome since user has welcomeexempt right' );
		}

		// Put possible welcomer into memcached, RT#14067
		if ( $user->getId() && self::isWelcomer( $user ) ) {
			$wgMemc->set( $wgMemc->makeKey( 'last-sysop-id' ), $user->getId(), 86400 );
			wfDebugLog( 'HAWelcome', 'Storing possible welcomer in memcached' );
		}

		if ( $title && !$wgCommandLineMode && $canWelcome ) {
			$welcomer = trim( wfMessage( 'welcome-user' )->inContentLanguage()->plain() );

			if ( in_array( $welcomer, [ '@disabled', '-' ] ) ) {
				// Check if talk page for current user exists, if they have made any edits, and
				// if the content model is wikitext. Only wikitext talk pages are supported.
				$talkPage = $user->getUserPage()->getTalkPage();
				if ( $talkPage && $talkPage->getContentModel() === CONTENT_MODEL_WIKITEXT ) {
					$talkWikiPage = WikiPage::factory( $talkPage );
					if ( !$talkWikiPage->exists() ) {
						$welcomeJob = new HAWelcomeJob(
							$title,
							[
								'is_anon'   => $user->isAnon(),
								'user_id'   => $user->getId(),
								'user_ip'   => $request->getRequest()->getIP(),
								'user_name' => $user->getName(),
							]
						);
						JobQueueGroup::singleton()->push( $welcomeJob );
					}
				}
			}
		}

		return true;
	}

	/**
	 * Check if a user can welcome other users
	 *
	 * @param User $user Instance of User class
	 * @return bool Status of the operation
	 */
	public static function isWelcomer( User &$user ) {
		global $wgHAWelcomeStaffGroupName;

		$sysop  = trim( wfMessage( 'welcome-user' )->plain() );
		$groups = $user->getEffectiveGroups();
		$result = false;

		// Bots can't welcome
		if ( !in_array( 'bot', $groups ) ) {
			if ( $sysop === '@sysop' ) {
				$result = in_array( 'sysop', $groups );
			} else {
				$result = in_array( 'sysop', $groups ) || in_array( $wgHAWelcomeStaffGroupName, $groups );
			}
		}

		return $result;
	}

	/**
	 * Wikia backport:
	 * Invalidates cached welcomer user ID if equal to changed user ID
	 * @author Kamil Koterba kamil@wikia-inc.com
	 *
	 * Adjusted to use the UserGroupsChanged hook rather than the UserRights hook, which was
	 * removed in 1.26, and to check if the removed group is sysop
	 *
	 * @param User $user
	 * @param array $added
	 * @param array $removed
	 * @param bool|User $performer
	 * @param string $reason
	 * @return bool
	 */
	public static function onUserGroupsChanged( User $user, array $added, array $removed, $performer, $reason ) {
		global $wgMemc;

		// Only remove the cache key if the user has the sysop group removed since other group
		// changes are not relevant
		if ( $user->getId() === $wgMemc->get( $wgMemc->makeKey( 'last-sysop-id' ) ) && in_array( 'sysop', $removed ) ) {
			$wgMemc->delete( $wgMemc->makeKey( 'last-sysop-id' ) );
		}

		return true;
	}

	/**
	 * Add the HAWelcomeWelcomeUsername to the list with reserved usernames to prevent users from
	 * using the welcome bot username.
	 *
	 * @param array $reservedUsernames
	 * @return bool
	 */
	public static function onUserGetReservedNames( array &$reservedUsernames ) {
		global $wgHAWelcomeWelcomeUsername;

		$reservedUsernames[] = $wgHAWelcomeWelcomeUsername;

		return true;
	}
}
