<?php

/*
 * This file is part of a XenForo add-on.
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace SV\WarningImprovements\Entity;

use XF\Entity\AbstractCategoryTree;
use XF\Mvc\Entity\Structure;

/**
 * COLUMNS
 * @property int|null warning_category_id
 * @property int warning_count
 * @property array allowed_user_group_ids
 * @property int|null parent_category_id
 * @property int display_order
 * @property int lft
 * @property int rgt
 * @property int depth
 * @property array breadcrumb_data
 *
 * GETTERS
 * @property mixed is_usable
 * @property \XF\Phrase title
 * @property mixed titleRaw
 *
 * RELATIONS
 * @property \XF\Entity\Phrase MasterTitle
 * @property \SV\WarningImprovements\Entity\WarningCategory Parent
 * @property \SV\WarningImprovements\Entity\WarningCategory[] ChildCategories
 * @property \XF\Entity\WarningDefinition[] WarningDefinitions
 * @property \XF\Entity\WarningAction[] WarningActions
 * @property \XF\Entity\PermissionCacheContent[] Permissions
 */
class WarningCategory extends AbstractCategoryTree
{
    public function getIsUsable()
    {
        if ($this->Parent && !$this->Parent->is_usable)
        {
            return false;
        }

        $visitor = \XF::visitor();
        foreach ($this->allowed_user_group_ids AS $userGroupId)
        {
            if ($userGroupId == -1 || $visitor->isMemberOf($userGroupId))
            {
                return true;
            }
        }

        return false;
    }

    /**
     * @return \XF\Phrase
     */
    public function getTitle()
    {
        return \XF::phrase($this->getPhraseName('title'));
    }

    public function getTitleRaw()
    {
        return $this->getTitle()->render();
    }

    public function getPhraseName($type)
    {
        return 'sv_warning_category_' . $type . '.' . $this->warning_category_id;
    }

    /**
     * @param $type
     *
     * @return \XF\Entity\Phrase
     */
    public function getMasterPhrase($type)
    {
        $relation = 'Master' . ucfirst($type);
        $phrase = $this->$relation;

        if (!$phrase)
        {
            $phrase = $this->_em->create('XF:Phrase');
            $phrase->title = $this->_getDeferredValue(function() use ($type) { return $this->getPhraseName($type); });
            $phrase->language_id = 0; // 0 = master
        }

        return $phrase;
    }

    public function warningAdded(\XF\Entity\WarningDefinition $warningDefinition)
    {
        $this->rebuildCounters();
    }

    public function warningRemoved(\XF\Entity\WarningDefinition $warningDefinition)
    {
        $this->rebuildCounters();
    }

    protected function _postSave()
    {
        if ($this->isUpdate())
        {
            if ($this->isChanged('warning_category_id'))
            {
                foreach ($this->_structure->relations AS $name => $relation)
                {
                    if ($relation['entity'] == 'XF:Phrase')
                    {
                        /** @var \XF\Entity\Phrase $maserPhrase */
                        $maserPhrase = $this->getExistingRelation($name);

                        if ($maserPhrase)
                        {
                            $type = substr(strtolower($name), 6); // strip Master
                            $maserPhrase->title = $this->getPhraseName($type);
                            $maserPhrase->save();
                        }
                    }
                }
            }
        }
    }

    protected function _preDelete()
    {
        if (empty($this->parent_category_id))
        {
            $categoryCount = $this->db()->fetchOne("
                SELECT COUNT(*)
                FROM xf_sv_warning_category
                WHERE sv_warning_category_id <> ?
            ", $this->sv_warning_category_id);

            if ($categoryCount === 0)
            {
                $this->error(\XF::phrase('sv_warning_improvements_last_category_cannot_be_deleted'));
                return false;
            }
        }
    }

    protected function _postDelete()
    {
        foreach ($this->_structure->relations AS $name => $relation)
        {
            if ($relation['entity'] == 'XF:Phrase')
            {
                if ($this->$name) // $name is the name of Relation
                {
                    $this->$name->delete();
                }
            }
        }

        foreach ($this->WarningDefinitions as $warningDefinition)
        {
            /** @var \SV\WarningImprovements\XF\Entity\WarningDefinition $warningDefinition */
            if ($warningDefinition->is_custom)
            {
                if (!empty($this->parent_category_id))
                {
                    $newCategoryId = $this->parent_category_id;
                }
                else
                {
                    $newCategoryId = $this->db()->fetchOne("
                        SELECT warning_category_id
                        FROM xf_sv_warning_category
                        WHERE warning_category_id <> ?
                    ", $this->warning_category_id);
                }

                $warningDefinition->sv_warning_category_id = $newCategoryId;
                $warningDefinition->save();

                continue;
            }

            $warningDefinition->delete();
        }

        foreach ($this->WarningActions AS $warningAction)
        {
            $warningAction->delete();
        }

        if ($this->getOption('delete_contents'))
        {
            /*$this->app()->jobManager()->enqueueUnique('sv_WarningImprovementsCategoryDelete' . $this->warning_category_id, 'SVW\WarningImprovements:CategoryDelete', [
                'warning_category_id' => $this->warning_category_id
            ]);*/
        }
    }

    public function rebuildCounters()
    {
        $this->rebuildWarningCount();

        return true;
    }

    public function rebuildWarningCount()
    {
        $warningCount = $this->db()->fetchOne("
			SELECT COUNT(*)
			FROM xf_warning_definition
			WHERE sv_warning_category_id = ?
		", $this->warning_category_id);

        $this->warning_count = max(0, $warningCount);

        return $this->warning_count;
    }

    public function getCategoryListExtras()
    {
        return [
            'warning_count' => $this->warning_count
        ];
    }

    /**
     * @param Structure $structure
     * @return Structure
     */
    public static function getStructure(Structure $structure)
    {
        $structure->table = 'xf_sv_warning_category';
        $structure->shortName = 'SV\WarningImprovements:WarningCategory';
        $structure->primaryKey = 'warning_category_id';
        $structure->columns = [
            'warning_category_id'        => ['type' => self::UINT, 'autoIncrement' => true, 'nullable' => true],
            'warning_count' => ['type' => self::UINT, 'default' => 0],
            'allowed_user_group_ids'     => [
                'type' => self::LIST_COMMA, 'default' => [\XF\Entity\User::GROUP_REG],
                'list' => ['type' => 'posint', 'unique' => true, 'sort' => SORT_NUMERIC]
            ]
        ];
        $structure->getters = [
            'is_usable' => true,
            'title' => true,
            'titleRaw' => true // onii-chan breadcrumb needs raw KappaPride
        ];
        $structure->relations = [
            'MasterTitle' => [
                'entity' => 'XF:Phrase',
                'type' => self::TO_ONE,
                'conditions' => [
                    ['language_id', '=', 0], // master
                    ['title', '=', 'sv_warning_category_title.', '$warning_category_id']
                ]
            ],
            'Parent' => [
                'entity' => 'SV\WarningImprovements:WarningCategory',
                'type' => self::TO_ONE,
                'conditions' => [['warning_category_id', '=', '$parent_category_id']],
                'primary' => true
            ],
            'ChildCategories' => [
                'entity' => 'SV\WarningImprovements:WarningCategory',
                'type' => self::TO_MANY,
                'conditions' => [['parent_category_id', '=', '$warning_category_id']],
                'primary' => true
            ],
            'WarningDefinitions' => [
                'entity' => 'XF:WarningDefinition',
                'type' => self::TO_MANY,
                'conditions' => [['sv_warning_category_id', '=', '$warning_category_id']],
                'primary' => true
            ],
            'WarningActions' => [
                'entity' => 'XF:WarningAction',
                'type' => self::TO_MANY,
                'conditions' => [['sv_warning_category_id', '=', '$warning_category_id']],
                'primary' => true
            ]
        ];
        $structure->options = [
            'delete_contents' => true
        ];

        static::addCategoryTreeStructureElements($structure);

        $structure->columns['parent_category_id']['nullable'] = true;

        $structure->behaviors['XF:TreeStructured']['titleField'] = 'titleRaw';

        return $structure;
    }
}
