<?php namespace Hampel\ArchiveSite\Repository;

use Hampel\ArchiveSite\Config\ProtectedUsers;
use XF\Entity\User;
use XF\Mvc\Entity\Repository;

class ArchiveUsers extends Repository
{
	/**
	 * @return \XF\Mvc\Entity\Finder
	 */
	public function protectedUsers()
	{
		return $this->finder('XF:User')
            ->with('Admin')
            ->whereOr([
                ['Admin.is_super_admin', '=', '1'],
                ['user_id', '=', ProtectedUsers::get()]
            ]);
	}

	/**
	 * IDs of every user the archive process must leave alone - each super admin, plus anything
	 * listed in $config['archiveSite']['protectedUsers'].
	 *
	 * The finders below exclude this list rather than testing Admin.is_super_admin themselves:
	 * the Admin relation is a left join, so a non-admin has NULL there and a comparison against
	 * it would exclude every ordinary user instead of the admins. Excluding by ID also keeps one
	 * definition of "protected" for the finders, the archive job and archiveUser()/restoreUser().
	 *
	 * @return array
	 */
	public function protectedUserIds()
	{
		return array_values($this->protectedUsers()->pluckFrom('user_id')->fetch()->toArray());
	}

	/**
	 * @return \XF\Mvc\Entity\Finder
	 */
	public function partiallyArchivedUsers()
	{
		return $this->finder('XF:User')
            ->with('Auth', true)
            ->with('ArchivedUser', false)
            ->where('user_id', '!=', $this->protectedUserIds())
            ->where('Auth.scheme_class', '=', 'XF:NoPassword')
            ->where('ArchivedUser.previous_state', '=', null);
	}

    /**
     * @return \XF\Mvc\Entity\Finder
     */
    public function archivedUsers()
    {
        // Auth is a left join: an account with no authentication row at all is archived like any
        // other, and must not vanish from the list for want of a password it never had
        return $this->finder('XF:User')
            ->with('Auth')
            ->with('ArchivedUser', true)
            ->where('user_id', '!=', $this->protectedUserIds())
            ->whereOr([
                ['Auth.scheme_class', '=', 'XF:NoPassword'],
                ['Auth.user_id', '=', null],
            ])
            ->where('ArchivedUser.previous_state', '!=', null);
    }

	/**
	 * @return \XF\Mvc\Entity\Finder
	 */
	public function activeUsers()
	{
		// Auth is a left join, for the same reason as in archivedUsers(): an account with no
		// authentication row is still an account the archive job will reach
		return $this->finder('XF:User')
            ->with('Auth')
            ->with('ArchivedUser', false)
            ->where('user_id', '!=', $this->protectedUserIds())
            ->whereOr([
                ['Auth.scheme_class', '!=', 'XF:NoPassword'],
                ['Auth.user_id', '=', null],
            ])
            ->where('ArchivedUser.previous_state', '=', null);
	}

	public function archiveUser(User $user)
	{
        // safety check, we shouldn't be passing these users in, but just in case
        if (ProtectedUsers::isProtected($user->user_id) || $user->is_super_admin)
        {
            return false;
        }

		// clear User Remember records so cookies no longer work
		$this->repository('XF:UserRemember')->clearUserRememberRecords($user->user_id);

        $previousScheme = $user->Auth ? $user->Auth->scheme_class : '';

        // remove users password - an account with no authentication row has none to remove, and
        // is archived all the same rather than stopping the run
        if ($user->Auth)
        {
            $user->Auth->setNoPassword();
            $user->Auth->save();
        }

        if (!$user->ArchivedUser)
        {
            $archivedUser = \XF::em()->create('Hampel\ArchiveSite:ArchivedUser');

            $archivedUser->bulkSet([
                'user_id' => $user->user_id,
                'previous_state' => $user->user_state,
                // what the account authenticated with before this, so restoreUser() can tell an
                // account that had a password from one that never did. Empty for an account with
                // no authentication row.
                'previous_scheme_class' => $previousScheme,
            ]);

            $archivedUser->save();

            // Keep this entity in step with the database. Left unhydrated, the relation still reads
            // null for as long as the entity is cached - so a restore run later in the same request,
            // as XenForo's queue runner can do, finds no archive row and silently restores nothing.
            $user->hydrateRelation('ArchivedUser', $archivedUser);
        }

        $user->user_state = 'disabled';
        $user->save();

        // archiving disables the account and removes the password. It deliberately leaves all
        // content, profile fields and personal details intact, so that restoreUser() can put
        // the account back: this is not a data-erasure mechanism.

        return true;
	}


	public function restoreUser(User $user)
	{
        // safety check, we shouldn't be passing these users in, but just in case
        if (ProtectedUsers::isProtected($user->user_id) || $user->is_super_admin)
        {
            return false;
        }

        if ($user->ArchivedUser)
        {
            $previousState = $user->ArchivedUser->previous_state;

            // Give the account a password again - a random one, so the owner still has to go
            // through a password reset - but ONLY where it had one before being archived.
            //
            // XenForo creates passwordless accounts for registrations through a connected
            // account, and another add-on can have its own passwordless scheme. Archiving
            // overwrote whatever that was with XF:NoPassword, so restoring used to hand those
            // accounts a real password they never had, leaving them reachable by lost-password
            // where before they were not. The recorded scheme is what tells them apart.
            //
            // The auth row is also left alone entirely when there is no archive row: this method
            // is reachable from archive:restore-user with any account, and resetting a member's
            // password because somebody typed the wrong name is not a restore.
            if ($user->Auth && $this->previousSchemeHadPassword($user->ArchivedUser->previous_scheme_class))
            {
                $user->Auth->resetPassword();
                $user->Auth->save();
            }

            // An account awaiting approval has a row in the approval queue, and XF\Entity\User
            // deletes it when the state leaves 'moderated' - which archiving does - then builds a
            // new one when it returns. It builds that one with getRelationOrDefault(), whose guard
            // is empty($this->_relations[$key]): an object is never empty, so the entity deleted a
            // moment ago is handed back still hydrated on the relation, and writing to it throws
            // "Attempted to set 'content_type' on a deleted entity". Dropping it lets core fetch
            // or build a live one. Only the moderated case reaches any of this, so nothing else
            // pays for the relation being read.
            if ($previousState == 'moderated' && $user->ApprovalQueue && $user->ApprovalQueue->isDeleted())
            {
                $user->hydrateRelation('ApprovalQueue', null);
            }

            $user->user_state = $previousState;
            $user->save();

            $user->ArchivedUser->delete();
            $user->hydrateRelation('ArchivedUser', null);
        }

        return true;
	}

    /**
     * Whether the scheme an account had before archiving provided a password at all.
     *
     * An empty value means it is not known - a row written before 2.2.0 recorded no scheme, and
     * so did an account with no authentication row - and the answer there is the behaviour every
     * earlier version had: reset the password. Most accounts did have one, and an upgrade cannot
     * find out retroactively.
     *
     * @param string $schemeClass
     *
     * @return bool
     */
    protected function previousSchemeHadPassword($schemeClass)
    {
        if (!$schemeClass)
        {
            return true;
        }

        try
        {
            // XenForo's own notion of it, so another add-on's passwordless scheme is covered too
            return \XF::app()->auth($schemeClass)->hasPassword();
        }
        catch (\Throwable $e)
        {
            // a scheme this install no longer has: treat it as it was treated before 2.2.0
            return true;
        }
    }
}
