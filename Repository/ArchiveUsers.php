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
	 * Accounts that look archived but that this add-on has no record of: shut, with no usable
	 * password, and no archive row - so nothing knows what their user_state used to be and they
	 * cannot be restored. An account whose password was removed before this add-on existed, or by
	 * some other route.
	 *
	 * **`user_state = 'disabled'` is what keeps ordinary members out of this set.** XenForo
	 * creates passwordless accounts for registrations through a connected account, and until
	 * 2.2.0 this finder reported every one of them as partially archived, on the strength of the
	 * authentication scheme alone. Such an account is not shut, so it is active.
	 *
	 * @return \XF\Mvc\Entity\Finder
	 */
	public function partiallyArchivedUsers()
	{
		return $this->finder('XF:User')
            ->with('Auth')
            ->with('ArchivedUser', false)
            ->where('user_id', '!=', $this->protectedUserIds())
            ->where('ArchivedUser.previous_state', '=', null)
            ->where('user_state', '=', 'disabled')
            ->whereOr([
                ['Auth.scheme_class', '=', 'XF:NoPassword'],
                ['Auth.user_id', '=', null],
            ]);
	}

    /**
     * Accounts this add-on archived, and can restore. **The archive row is the whole test.**
     *
     * Until 2.2.0 this also required the account to have no password, so an archived account
     * whose scheme was anything else - an administrator set a password on it, or another add-on
     * owns its authentication - appeared in none of the four sets at all. The row is what records
     * the previous state, so the row is what makes an account restorable.
     *
     * @return \XF\Mvc\Entity\Finder
     */
    public function archivedUsers()
    {
        // Auth is a left join so the entity arrives hydrated; nothing is filtered on it
        return $this->finder('XF:User')
            ->with('Auth')
            ->with('ArchivedUser', true)
            ->where('user_id', '!=', $this->protectedUserIds())
            ->where('ArchivedUser.previous_state', '!=', null);
    }

	/**
	 * Everything else: no archive row, and not shut without a password.
	 *
	 * The second condition is the complement of partiallyArchivedUsers(), so the three sets
	 * partition every unprotected account - which `test_every_unprotected_account_is_in_exactly
	 * _one_set` asserts against the forum rather than against the SQL.
	 *
	 * **`Auth.scheme_class != 'XF:NoPassword'` is NULL, and therefore false, for an account with
	 * no authentication row - which is what is wanted here**, since such an account has no
	 * password either. That is the opposite of the trap in `protectedUserIds()`, where a NULL
	 * from a left join silently excluded everybody; it is safe here only because it sits in an OR
	 * beside a condition that does not depend on the join.
	 *
	 * @return \XF\Mvc\Entity\Finder
	 */
	public function activeUsers()
	{
		return $this->finder('XF:User')
            ->with('Auth')
            ->with('ArchivedUser', false)
            ->where('user_id', '!=', $this->protectedUserIds())
            ->where('ArchivedUser.previous_state', '=', null)
            ->whereOr([
                ['user_state', '!=', 'disabled'],
                ['Auth.scheme_class', '!=', 'XF:NoPassword'],
            ]);
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

        // and every other way in: a passkey authenticates on its own, and a two-step row is
        // configuration nothing else prunes. Archiving is one way, so these go with the password
        // rather than being recorded for a restore - there is nothing restorable in them anyway.
        $this->clearCredentials($user);

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
     * Remove every passkey and two-step record the account has.
     *
     * Two different things, and only the first is a credential. A passkey in `xf_passkey`
     * authenticates on its own, through a route that never reaches `Login::validate()` - so until
     * this was cleared, a passkey holder on an archived forum authenticated successfully and met
     * XenForo's generic disabled-account page instead of this add-on's message. Two-step rows are
     * the configuration guarding a password login, plus `xf_user_tfa_trusted`, the devices allowed
     * to skip it.
     *
     * **The order is load-bearing.** Deleting the passkeys first lets XenForo's own `Passkey`
     * entity retire the `passkey` two-step provider row it maintains beside them, and
     * `disableTfaForUser()` then clears whatever is left and puts `user_option.use_tfa` back to 0.
     * That flag is the half worth being careful about: set with no provider rows behind it, it
     * asks a restored account for a code nothing can issue.
     *
     * **`xf_tfa_attempt` is deliberately left alone.** It is rate limiting rather than anything
     * the account owns, and XenForo's own cron deletes every row in it over a day old.
     *
     * @param User $user
     */
    protected function clearCredentials(User $user)
    {
        // Passkeys arrived in XF 2.3 and this add-on's floor is 2.2, where the table, the entity
        // and the repository all do not exist - so resolving any of them there is a fatal during
        // an archive run rather than an empty result.
        if (\XF::$versionId >= 2030000)
        {
            $passkeys = $this->repository('XF:Passkey')->findPasskeysForUser($user)->fetch();

            // one at a time through the entity, rather than a delete on the table, so that an
            // add-on with its own data hanging off a passkey is told the passkey has gone
            foreach ($passkeys AS $passkey)
            {
                $passkey->delete();
            }
        }

        // every provider row, every trusted device, and the use_tfa flag, which is XenForo's own
        // definition of clearing two-step: it is what the account security page calls.
        $this->repository('XF:Tfa')->disableTfaForUser($user);
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
