<?php

namespace SV\WarningImprovements\XF\Entity;

use XF\Mvc\Entity\Structure;

/**
 * Extends \XF\Entity\UserChangeTemp
 *
 * @property \XF\Entity\Phrase name
 * @property \XF\Entity\Phrase result
 * @property bool is_expired
 * @property bool is_permanent
 * @property int effective_expiry_date
 */
class UserChangeTemp extends XFCP_UserChangeTemp
{
    /**
     * @return \XF\Phrase
     */
    public function getName()
    {
        $name = 'n_a';

        switch ($this->action_type)
        {
            case 'groups':
                $name = 'sv_warning_action_added_to_user_groups';
                break;

            case 'field':
                $name = 'discouraged';
                break;
        }

        return \XF::phrase($name);
    }

    /**
     * @return \XF\Phrase
     */
    public function getResult()
    {
        $result = 'n_a';

        switch ($this->action_type)
        {
            case 'groups':
                $result = 'n_a';

                if (substr($this->action_modifier, 0, 15) === 'warning_action_')
                {
                    $userGroupNames = [];

                    /** @var \SV\WarningImprovements\XF\Repository\UserChangeTemp $userGroupRepo */
                    $userGroupRepo = $this->repository('XF:UserChangeTemp');
                    $userGroups = $userGroupRepo->getCachedUserGroupsList();
                    $userGroupChangeSet = $userGroupRepo->getCachedUserGroupChangeList($this->user_id);

                    if (isset($userGroupChangeSet[$this->action_modifier]))
                    {
                        foreach ($userGroupChangeSet[$this->action_modifier] as $userGroupId)
                        {
                            if (!empty($userGroups[$userGroupId]))
                            {
                                $userGroupNames[] = $userGroups[$userGroupId]->title;
                            }
                        }
                    }

                    if (!empty($userGroupNames))
                    {
                        $result = implode(',', $userGroupNames);
                    }
                }

                break;

            case 'field':
                $result = ($this->new_value === '1') ? 'yes' : 'no';
                break;
        }

        return \XF::phrase($result);
    }

    /**
     * @return bool
     */
    public function getIsExpired()
    {
        return ($this->expiry_date <= \XF::$time && !$this->is_permanent);
    }

    public function getIsPermanent()
    {
        return ($this->effective_expiry_date === null);
    }

    /**
     * @return int|null
     */
    public function getEffectiveExpiryDate()
    {
        $visitor = \XF::visitor();

        $effectiveExpiryDate = $this->expiry_date;
        // need to check how this expires
        if ($effectiveExpiryDate === null && preg_match('#^warning_action_(\d+)$#', $this->action_modifier, $matches))
        {
            $warningActionId = $matches[1];
            /** @var WarningAction $warningAction */
            $warningAction = $this->em()->find('XF:WarningAction', $warningActionId);
            if ($warningAction && $warningAction->action_length_type === 'points')
            {
                // compute when the minimum level of points expire.
                $effectiveExpiryDate = $this->db()->fetchOne(
                    'select expiry_date
                            from
                            (
                                select @pointSum := @pointSum+ points AS pointSum, permanent, expiry_date 
                                from
                                (
                                    select points, if(expiry_date = 0, 1, 0) as permanent, expiry_date 
                                    from xf_warning 
                                    where user_id = ? and (expiry_date >= unix_timestamp() or expiry_date = 0)
                                    order by permanent, expiry_date
                                ) a, (SELECT @pointSum :=0) AS dummy
                                order by permanent, expiry_date
                            ) b
                            where pointSum >= ?
                            order by permanent, expiry_date
                            limit 1', [$this->user_id, $warningAction->points]);
                if (!$effectiveExpiryDate)
                {
                    $effectiveExpiryDate = null;
                }
            }
        }

        if (!$visitor->user_id ||
            $visitor->hasPermission('general', 'viewWarning'))
        {
            return $effectiveExpiryDate;
        }

        if ($effectiveExpiryDate)
        {
            $effectiveExpiryDate = ($effectiveExpiryDate - ($effectiveExpiryDate % 3600)) + 3600;
        }

        return $effectiveExpiryDate;
    }

    /**
     * @param string|null $error
     * @return bool
     */
    public function canViewWarningAction(&$error = null)
    {
        /** @var User $user */
        $user = $this->User;

        if (!$user->user_id)
        {
            return false;
        }

        if ($this->action_modifier === 'is_discouraged' && $this->action_type === 'action_type' && !$this->canViewDiscouragedWarningAction($error))
        {
            return false;
        }

        return $user->canViewWarningActions($error);
    }

    /**
     * @param string|null $error
     * @return bool
     */
    public function canViewNonSummaryWarningAction(&$error = null)
    {
        /** @var User $visitor */
        $visitor = \XF::visitor();

        if (!$visitor->user_id)
        {
            return false;
        }

        return $visitor->canViewNonSummaryWarningActions($error);
    }

    /**
     * @param string|null $error
     * @return bool
     */
    public function canViewDiscouragedWarningAction(&$error = null)
    {
        /** @var User $visitor */
        $visitor = \XF::visitor();

        if (!$visitor->user_id)
        {
            return false;
        }

        return $visitor->canViewDiscouragedWarningActions($error);
    }

    /**
     * @param string $error
     * @return bool
     */
    public function canEditWarningAction(&$error = '')
    {
        /** @var User $visitor */
        $visitor = \XF::visitor();

        if (!$visitor->user_id)
        {
            return false;
        }

        return $visitor->canEditWarningActions($error);
    }

    protected function _postSave()
    {
        parent::_postSave();
        // big hammer reset the getter cache
        $this->_getterCache = [];

        $this->_getWarningRepo()->updatePendingExpiryFor($this->User, true);
    }

    protected function _postDelete()
    {
        parent::_postDelete();

        $this->_getWarningRepo()->updatePendingExpiryFor($this->User, true);
    }

    public static function getStructure(Structure $structure)
    {
        $structure = parent::getStructure($structure);

        $structure->getters['name'] = true;
        $structure->getters['result'] = true;
        $structure->getters['is_expired'] = true;
        $structure->getters['effective_expiry_date'] = true;
        $structure->getters['is_permanent'] = true;

        return $structure;
    }

    /**
     * @return \XF\Mvc\Entity\Repository|\XF\Repository\Warning|\SV\WarningImprovements\XF\Repository\Warning
     */
    protected function _getWarningRepo()
    {
        return $this->repository('XF:Warning');
    }
}
