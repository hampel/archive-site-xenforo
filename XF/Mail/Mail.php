<?php namespace Hampel\ArchiveSite\XF\Mail;

use Hampel\ArchiveSite\Config\AllowMail;
use Hampel\ArchiveSite\Config\ProtectedUsers;

if (\XF::$versionId >= 2030000) {
    class Mail extends XFCP_Mail
    {
        public function setToUser(\XF\Entity\User $user): \XF\Mail\Mail
        {
            // $config['archiveSite']['allowMail'] turns the restriction off outright - see Config\AllowMail
            if (!AllowMail::enabled() && !$user->is_super_admin && !ProtectedUsers::isProtected($user->user_id)) {
                $this->setupError = new \Exception("Trying to send email to archived user (ID: $user->user_id)");

                return $this;
            }

            parent::setToUser($user);

            return $this;
        }
    }
}
else // XF 2.2
{
    class Mail extends XFCP_Mail
    {
        public function setToUser(\XF\Entity\User $user)
        {
            // $config['archiveSite']['allowMail'] turns the restriction off outright - see Config\AllowMail
            if (!AllowMail::enabled() && !$user->is_super_admin && !ProtectedUsers::isProtected($user->user_id)) {
                $this->setupError = new \Exception("Trying to send email to archived user (ID: $user->user_id)");

                return $this;
            }

            parent::setToUser($user);

            return $this;
        }
    }
}