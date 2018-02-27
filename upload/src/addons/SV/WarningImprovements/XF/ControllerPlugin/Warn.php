<?php

namespace SV\WarningImprovements\XF\ControllerPlugin;

use XF\Mvc\Entity\Entity;

/**
 * Extends \XF\ControllerPlugin\Warn
 */
class Warn extends XFCP_Warn
{
    public function actionWarn($contentType, Entity $content, $warnUrl, array $breadcrumbs = [])
    {
        $response = parent::actionWarn($contentType, $content, $warnUrl, $breadcrumbs);

        if ($response instanceof \XF\Mvc\Reply\Redirect)
        {
            if (empty($response->getMessage()))
            {
                $response->setMessage(\XF::phrase('sv_issued_warning'));
            }

            return $response;
        }
        else if ($response instanceof \XF\Mvc\Reply\View)
        {
            $categoryRepo = $this->getWarningCategoryRepo();
            $categoryTree = $categoryRepo->createCategoryTree(null, 0, true);

            /** @var \SV\WarningImprovements\XF\Repository\Warning $warningRepo */
            $warningRepo = $this->repository('XF:Warning');
            $warnings = $warningRepo->findWarningDefinitionsForListGroupedByCategory();

            /** @var \SV\WarningImprovements\XF\Entity\User $user */
            $user = $response->getParam('user');
            $previousWarnings = null;

            if ($user)
            {
                $previousWarnings = $warningRepo->findUserWarningsForList($user->user_id)->limit(5); // make this a option?
            }

            $response->setParams([
                'warnings' => $warnings,
                'previousWarnings' => $previousWarnings,

                'categoryTree' => $categoryTree
            ]);
        }

        return $response;
    }

    protected function getWarningFillerReply(
        \XF\Warning\AbstractHandler $warningHandler,
        \XF\Entity\User $user,
        $contentType,
        \XF\Mvc\Entity\Entity $content,
        array $input
    )
    {
        $response = parent::getWarningFillerReply($warningHandler, $user, $contentType, $content, $input);

        if ($response instanceof \XF\Mvc\Reply\View && $input['warning_definition_id'] === 0)
        {
            /** @var \SV\WarningImprovements\XF\Repository\Warning $warningRepo */
            $warningRepo = $this->repository('XF:Warning');

            /** @var \XF\Entity\WarningDefinition $definition */
            $definition = $warningRepo->getCustomWarning();

            if ($definition)
            {
                list($conversationTitle, $conversationMessage) = $definition->getSpecificConversationContent(
                    $user, $contentType, $content
                );
            }
            else
            {
                $conversationTitle = '';
                $conversationMessage = '';
            }

            $response->setParams([
                'definition' => $definition,
                'conversationTitle' => $conversationTitle,
                'conversationMessage' => $conversationMessage
            ]);
        }

        return $response;
    }

    protected function setupWarnService(\XF\Warning\AbstractHandler $warningHandler, \XF\Entity\User $user, $contentType, \XF\Mvc\Entity\Entity $content, array $input)
	{
	    \SV\WarningImprovements\Listener::$warningInput = $input;
		return parent::setupWarnService($warningHandler, $user, $contentType, $content, $input);
	}

    /**
     * @return \SV\WarningImprovements\Repository\WarningCategory
     */
    protected function getWarningCategoryRepo()
    {
        return $this->repository('SV\WarningImprovements:WarningCategory');
    }
}