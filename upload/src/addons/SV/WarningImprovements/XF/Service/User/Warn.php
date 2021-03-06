<?php

namespace SV\WarningImprovements\XF\Service\User;

use SV\WarningImprovements\Globals;
use XF\Entity\Warning;
use XF\Entity\WarningDefinition;

/**
 * Extends \XF\Service\User\Warn
 */
class Warn extends XFCP_Warn
{
    protected $sendAlert = false;
    /**
     * @var \XF\Service\Conversation\Creator
     */
    protected $conversationCreator;

    /**
     * @param bool $sendAlert
     */
    public function setSendAlert($sendAlert)
    {
        $this->sendAlert = $sendAlert;
    }

    public function setFromDefinition(WarningDefinition $definition, $points = null, $expiry = null)
    {
        $this->setSendAlert(!empty(Globals::$warningInput['send_warning_alert']));
        $custom_title = !empty(Globals::$warningInput['custom_title']) ? Globals::$warningInput['custom_title'] : null;
        /** @var \SV\WarningImprovements\XF\Entity\WarningDefinition $definition */
        $return = parent::setFromDefinition($definition, $points, $expiry);

        if ($definition->warning_definition_id === 0)
        {
            $this->warning->hydrateRelation('Definition', $definition);
        }

        // force empty because title is already being set from warning definition entity
        if ($this->warning->warning_definition_id === 0)
        {
            $this->warning->title = '';
        }

        if ($custom_title && ($definition->sv_custom_title || $definition->warning_definition_id === 0))
        {
            $this->warning->title = $custom_title;
        }

        return $return;
    }

    public function setFromCustom($title, $points, $expiry)
    {
        Globals::$warningInput['custom_title'] = $title;
        return $this->setFromDefinition($this->getCustomWarningDefinition(), $points, $expiry);
    }

    /**
     * @return \SV\WarningImprovements\XF\Entity\WarningDefinition
     */
    protected function getCustomWarningDefinition()
    {
        /** @var \SV\WarningImprovements\XF\Repository\Warning $warningRepo */
        $warningRepo = $this->repository('XF:Warning');

        return $warningRepo->getCustomWarningDefinition();
    }

    /**
     * @param bool $conversation
     * @return \SV\WarningImprovements\XF\Entity\User|\XF\Entity\User|\XF\Mvc\Entity\Entity
     */
    protected function getWarnedByForUser($conversation)
    {
        /** @var \SV\WarningImprovements\XF\Entity\Warning $warning */
        $warning = $this->warning;

        if ($conversation && empty(\XF::options()->svWarningImprovAnonymizeConversations))
        {
            return $warning->WarnedBy;
        }


        return $warning->User->canViewIssuer() ? $warning->WarnedBy : $warning->getAnonymizedIssuer();
    }

    protected function _save()
    {
        $db = \XF::db();
        $db->beginTransaction();

        $warning = parent::_save();

        if ($this->sendAlert)
        {
            $warnedBy = $this->getWarnedByForUser(false);
            /** @var \XF\Repository\UserAlert $alertRepo */
            $alertRepo = $this->repository('XF:UserAlert');
            $alertRepo->alertFromUser($warning->User, $warnedBy, 'warning_alert', $warning->warning_id, 'warning');
        }

        $this->warningActionNotifications();

        $db->commit();

        if ($this->conversationCreator)
        {
            $warnedBy = $this->getWarnedByForUser(true);
            // workaround for \XF\Service\Conversation\Pusher::setInitialProperties requiring a user to be set on the Message's User attribute
            $this->conversationCreator->getMessage()->hydrateRelation('User', $warnedBy);

            \XF::asVisitor($warnedBy, function () {
                $this->conversationCreator->sendNotifications();
            });
        }

        return $warning;
    }

    public function warningActionNotifications()
    {
        $options = $this->app->options();
        $postSummaryForumId = $options->sv_post_warning_summaryForum;
        $postSummaryThreadId = $options->sv_post_warning_summary;

        if (!$postSummaryForumId && !$postSummaryThreadId)
        {
            return;
        }

        /** @var \SV\WarningImprovements\XF\Repository\Warning $warningRepo */
        $warningRepo = \XF::repository('XF:Warning');
        $params = $warningRepo->getSvWarningReplaceables(
            $this->user,
            $this->warning,
            null,
            true,
            $this->contentAction, $this->contentActionOptions
        );

        $warningUser = \XF::visitor(); //$this->user;

        if ($postSummaryForumId &&
            ($forum = $this->em()->find('XF:Forum', $postSummaryForumId)))
        {
            /** @var \XF\Entity\Forum|\SV\MultiPrefix\XF\Entity\Forum $forum */
            /** @var \XF\Service\Thread\Creator $threadCreator */
            $threadCreator = \XF::asVisitor($warningUser, function () use ($forum, $params) {
                /** @var \XF\Service\Thread\Creator $threadCreator */
                $threadCreator = $this->service('XF:Thread\Creator', $forum);
                $threadCreator->setIsAutomated();

                $defaultPrefix = isset($forum->sv_default_prefix_ids) ? $forum->sv_default_prefix_ids : $forum->default_prefix_id;
                if ($defaultPrefix)
                {
                    $threadCreator->setPrefix($defaultPrefix);
                }

                $title = \XF::phrase('Warning_Summary.Title', $params)->render('raw');
                $messageContent = \XF::phrase('Warning_Summary.Message', $params)->render('raw');

                $threadCreator->setContent($title, $messageContent);
                $threadCreator->save();

                return $threadCreator;
            });

            \XF::runLater(function () use ($threadCreator, $warningUser){
                \XF::asVisitor($warningUser, function () use ($threadCreator) {
                    $threadCreator->sendNotifications();
                });
            });
        }
        else if ($postSummaryThreadId &&
                 ($thread = $this->em()->find('XF:Thread', $postSummaryThreadId)))
        {
            /** @var \XF\Entity\Thread $thread */
            $threadReplier = \XF::asVisitor($warningUser, function () use ($thread, $params) {
                /** @var \XF\Service\Thread\Replier $threadReplier */
                $threadReplier = $this->service('XF:Thread\Replier', $thread);
                $threadReplier->setIsAutomated();

                $messageContent = \XF::phrase('Warning_Summary.Message', $params)->render('raw');

                $threadReplier->setMessage($messageContent);
                $threadReplier->save();

                return $threadReplier;
            });

            \XF::runLater(function () use ($threadReplier, $warningUser){
                \XF::asVisitor($warningUser, function () use ($threadReplier) {
                    $threadReplier->sendNotifications();
                });
            });
        }
    }

    protected function _validate()
    {
        $errors = parent::_validate();

        if (!$this->warning->canView($error))
        {
            $errors[] = $error;
        }

        return $errors;
    }

    protected function setupConversation(Warning $warning)
    {
        $warnedBy = $this->getWarnedByForUser(true);
        $realWarningBy = $this->warningBy;
        $this->warningBy = $warnedBy;
        try
        {
            /** @var \XF\Service\Conversation\Creator $creator */
            $creator = \XF::asVisitor($warnedBy, function () use ($warning) {
                return parent::setupConversation($warning);
            });
        }
        finally
        {
            $this->warningBy = $realWarningBy;
        }

        $conversationTitle = $this->conversationTitle;
        $conversationMessage = $this->conversationMessage;

        /** @var \SV\WarningImprovements\XF\Repository\Warning $warningRepo */
        $warningRepo = \XF::repository('XF:Warning');
        $replace = $warningRepo->getSvWarningReplaceables(
            $warning->User,
            $warning,
            null,
            false,
            $this->contentAction, $this->contentActionOptions
        );

        $conversationTitle = strtr(strval($conversationTitle), $replace);
        $conversationMessage = strtr(strval($conversationMessage), $replace);

        $creator->setContent($conversationTitle, $conversationMessage);
        $this->conversationCreator = $creator;
        $creator->setAutoSendNotifications(false);

        return $creator;
    }

    protected function sendConversation(Warning $warning)
    {
        Globals::$warningObj = $this->warning;
        try
        {
            return parent::sendConversation($warning);
        }
        finally
        {
            Globals::$warningObj = null;
        }
    }
}