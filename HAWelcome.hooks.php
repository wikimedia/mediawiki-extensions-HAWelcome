<?php

use MediaWiki\MediaWikiServices;
use MediaWiki\Revision\RevisionRecord;
use MediaWiki\Storage\EditResult;
use MediaWiki\Storage\Hook\PageSaveCompleteHook;
use MediaWiki\User\UserFactory;
use MediaWiki\User\UserGroupManager;
use MediaWiki\User\UserIdentity;

class HAWelcomeHooks implements PageSaveCompleteHook {
	/**
	 * @var ReadOnlyMode
	 */
	private $readOnlyMode;

	/**
	 * @var UserGroupManager
	 */
	private $userGroupManager;

	/**
	 * @var UserFactory
	 */
	private $userFactory;

	/**
	 * @param ReadOnlyMode $readOnlyMode
	 * @param UserGroupManager $userGroupManager
	 * @param UserFactory $userFactory
	 */
	public function __construct(
		ReadOnlyMode $readOnlyMode,
		UserGroupManager $userGroupManager,
		UserFactory $userFactory
	) {
		$this->readOnlyMode = $readOnlyMode;
		$this->userGroupManager = $userGroupManager;
		$this->userFactory = $userFactory;
	}

	/**
	 * @param WikiPage $article
	 * @param UserIdentity $userIdentity
	 * @param string $summary
	 * @param int $flags
	 * @param RevisionRecord $revisionRecord
	 * @param EditResult $editResult
	 * @throws MWException
	 */
	public function onPageSaveComplete(
		$article,
		$userIdentity,
		$summary,
		$flags,
		$revisionRecord,
		$editResult
	) {
		$context = RequestContext::getMain();

		// Do not create job when DB is locked (rt#12229)
		// Ditto for when we're in command line mode
		if ( $this->readOnlyMode->isReadOnly() || MW_ENTRY_POINT === 'cli' ) {
			return;
		}

		$title = $article->getTitle();
		if ( !$title ) {
			return;
		}

		// Do nothing if this extension is disabled by on-wiki configuration
		$welcomer = trim( wfMessage( 'welcome-user' )->inContentLanguage()->plain() );
		if ( in_array( $welcomer, [ '@disabled', '-' ] ) ) {
			return;
		}

		$user = $this->userFactory->newFromUserIdentity( $userIdentity );

		// Get groups for user rt#12215
		$canWelcome = !$user->isAllowed( 'welcomeexempt' );
		if ( !$canWelcome ) {
			wfDebugLog( 'HAWelcome', 'Skipping welcome since user has welcomeexempt right' );
			return;
		}

		// Put possible welcomer into cache, RT#14067
		if ( $user->getId() && $this->isWelcomer( $user ) ) {
			$cache = MediaWikiServices::getInstance()->getMainWANObjectCache();
			$cache->set( $cache->makeKey( 'last-sysop-id' ), $user->getId(), 86400 );
			wfDebugLog( 'HAWelcome', 'Storing possible welcomer in cache' );
		}

		// Check if talk page for current user exists, if they have made any edits, and
		// if the content model is wikitext. Only wikitext talk pages are supported.
		$talkPage = $user->getUserPage()->getTalkPage();
		if ( $talkPage && $talkPage->getContentModel() === CONTENT_MODEL_WIKITEXT ) {
			$talkWikiPage = MediaWikiServices::getInstance()->getWikiPageFactory()->newFromTitle( $talkPage );
			if ( !$talkWikiPage->exists() ) {
				$welcomeJob = new HAWelcomeJob(
					$title,
					[
						'is_anon'   => $user->isAnon(),
						'user_id'   => $user->getId(),
						'user_ip'   => $context->getRequest()->getIP(),
						'user_name' => $user->getName(),
					],
					$this->userFactory,
				);
				MediaWikiServices::getInstance()->getJobQueueGroup()->push( $welcomeJob );
			}
		}
	}

	/**
	 * Check if a user can welcome other users
	 *
	 * @param UserIdentity $user Instance of UserIdentity
	 * @return bool Status of the operation
	 */
	public function isWelcomer( UserIdentity $user ) {
		global $wgHAWelcomeStaffGroupName;

		$sysop  = trim( wfMessage( 'welcome-user' )->plain() );
		$groups = $this->userGroupManager->getUserEffectiveGroups( $user );
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
