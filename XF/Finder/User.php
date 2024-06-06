<?php namespace Hampel\ArchiveSite\XF\Finder;

class User extends XFCP_User
{
    public function isValidUser($recentlyActive = false)
    {
        // replace this function to include archived users as valid so stats remain accurate

        $this->with('ArchivedUser', false);

        $this->where('is_banned', false);
//        $this->where('user_state', 'valid');
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
