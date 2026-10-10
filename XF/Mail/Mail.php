<?php namespace Hampel\ArchiveSite\XF\Mail;

use Hampel\ArchiveSite\Mail\Restriction;

/**
 * Both of XenForo's recipient setters refuse anyone this forum may not mail.
 *
 * The class is declared twice because XenForo 2.3 gave `setTo()` and `setToUser()` a
 * `: \XF\Mail\Mail` return type, and an incompatible signature is a fatal rather than a warning.
 * The two bodies are therefore identical apart from those types, and the decision they ask for
 * lives in Hampel\ArchiveSite\Mail\Restriction so that it cannot drift between them.
 *
 * `setToUser()` returns before calling the parent when it refuses, so a blocked member produces
 * one error rather than two - the parent would otherwise reach the `setTo()` override below.
 */
if (\XF::$versionId >= 2030000) {
    class Mail extends XFCP_Mail
    {
        public function setToUser(\XF\Entity\User $user): \XF\Mail\Mail
        {
            if (!Restriction::allowsUser($user)) {
                $this->setupError = Restriction::error("ID: $user->user_id");

                return $this;
            }

            parent::setToUser($user);

            return $this;
        }

        public function setTo($email, $name = null): \XF\Mail\Mail
        {
            $blocked = Restriction::blockedUserIds($email);

            if ($blocked) {
                $this->setupError = Restriction::error('ID: ' . implode(', ', $blocked));

                return $this;
            }

            parent::setTo($email, $name);

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
            if (!Restriction::allowsUser($user)) {
                $this->setupError = Restriction::error("ID: $user->user_id");

                return $this;
            }

            parent::setToUser($user);

            return $this;
        }

        public function setTo($email, $name = null)
        {
            $blocked = Restriction::blockedUserIds($email);

            if ($blocked) {
                $this->setupError = Restriction::error('ID: ' . implode(', ', $blocked));

                return $this;
            }

            parent::setTo($email, $name);

            return $this;
        }
    }
}
