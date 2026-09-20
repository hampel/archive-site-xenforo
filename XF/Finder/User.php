<?php namespace Hampel\ArchiveSite\XF\Finder;

class User extends XFCP_User
{
    public function isValidUser($recentlyActive = false)
    {
        // replace this function to include archived users as valid so stats remain accurate

        $this->with('ArchivedUser', false);

        $this->where('is_banned', false);
        // core checks user_state = 'valid'; widened so an archived user whose previous
        // state was valid still counts, keeping member counts and statistics honest
        $this->whereOr(
            ['user_state', 'valid'],
            ['ArchivedUser.previous_state', 'valid']
        );
        if ($recentlyActive)
        {
            $this->isRecentlyActive();
        }
        return $this;
    }
}
