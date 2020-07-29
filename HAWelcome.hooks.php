<?php

use MediaWiki\MediaWikiServices;
use MediaWiki\User\UserIdentity;

class HAWelcomeHooks {
	/**
	 * Static method called as hook for PageSaveComplete
	 *
	 * @param WikiPage $article
	 * @param User $userIdentity
	 */
	public static function onPageSaveComplete( WikiPage $article, UserIdentity $userIdentity ) {
		global $wgCommandLineMode;

		$request = RequestContext::getMain();

		// Do not create job when DB is locked (rt#12229)
		if ( wfReadOnly() ) {
			return;
		}

		$title = $article->getTitle();
		$user = User::newFromIdentity( $userIdentity );

		// Get groups for user rt#12215
		$canWelcome = !$user->isAllowed( 'welcomeexempt' );
		if ( !$canWelcome ) {
			wfDebugLog( 'HAWelcome', 'Skipping welcome since user has welcomeexempt right' );
		}

		// Put possible welcomer into cache, RT#14067
		if ( $user->getId() && self::isWelcomer( $user ) ) {
			$cache = MediaWikiServices::getInstance()->getMainWANObjectCache();
			$cache->set( $cache->makeKey( 'last-sysop-id' ), $user->getId(), 86400 );
			wfDebugLog( 'HAWelcome', 'Storing possible welcomer in cache' );
		}

		if ( $title && !$wgCommandLineMode && $canWelcome ) {
			$welcomer = trim( wfMessage( 'welcome-user' )->inContentLanguage()->plain() );

			if ( !in_array( $welcomer, [ '@disabled', '-' ] ) ) {
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
	}

	/**
	 * Check if a user can welcome other users
	 *
	 * @param User &$user Instance of User class
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
	 */
	public static function onUserGroupsChanged( User $user, array $added, array $removed, $performer, $reason ) {
		// Only remove the cache key if the user has the sysop group removed since other group
		// changes are not relevant
		$cache = MediaWikiServices::getInstance()->getMainWANObjectCache();
		if ( $user->getId() === $cache->get( $cache->makeKey( 'last-sysop-id' ) ) && in_array( 'sysop', $removed ) ) {
			$cache->delete( $cache->makeKey( 'last-sysop-id' ) );
		}
	}

	/**
	 * Add the HAWelcomeWelcomeUsername to the list with reserved usernames to prevent users from
	 * using the welcome bot username.
	 *
	 * @param array &$reservedUsernames
	 */
	public static function onUserGetReservedNames( array &$reservedUsernames ) {
		global $wgHAWelcomeWelcomeUsername;

		$reservedUsernames[] = $wgHAWelcomeWelcomeUsername;
	}
}
