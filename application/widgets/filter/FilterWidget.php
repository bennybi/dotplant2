<?php

namespace app\widgets\filter;

use app\models\Object;
use app\models\ObjectStaticValues;
use app\models\Property;
use app\models\PropertyGroup;
use app\models\PropertyStaticValues;
use Yii;
use yii\base\Widget;
use yii\caching\TagDependency;
use yii\db\Query;
use yii\helpers\ArrayHelper;
use yii\helpers\Json;

class FilterWidget extends Widget
{
    private $possibleSelections = null;
    public $categoryGroupId = 0;
    public $currentSelections = [];
    public $goBackAlignment = 'left';
    public $objectId = null;
    public $onlyAvailableFilters = true;
    public $disableInsteadOfHide = false;
    public $route = '/product/list';
    public $title = 'Filter';
    public $viewFile = 'filterWidget';
    private $disabled_ids = [];
    public $render_dynamic = true;

    /**
     * @var null|array Array of group ids to display in filter, null to display all available for Object
     */
    public $onlyGroupsIds = null;

    /**
     * @var array Additional params passed to filter view
     */
    public $additionalViewParams = [];

    /**
     * @inheritdoc
     */
    public function run()
    {
        Yii::beginProfile("FilterWidget");

        $view = $this->getView();
        FilterWidgetAsset::register($view);
        $view->registerJs(
            "jQuery('#{$this->id}').getFilters();"
        );

        Yii::beginProfile("GetPossibleSelections");
        $this->getPossibleSelections();
        Yii::endProfile("GetPossibleSelections");

        $result = $this->render(
            $this->viewFile,
            ArrayHelper::merge([
                'id' => $this->id,
                'current_selections' => $this->currentSelections,
                'possible_selections' => $this->possibleSelections,
                'object_id' => $this->objectId,
                'title' => $this->title,
                'go_back_alignment' => $this->goBackAlignment,
                'route' => $this->route,
                'category_group_id' => $this->categoryGroupId,
                'disabled_ids' => $this->disabled_ids,
                'render_dynamic' => $this->render_dynamic,
            ], $this->additionalViewParams)
        );

        Yii::endProfile("FilterWidget");

        return $result;
    }

    public function getPossibleSelections()
    {
        $data = [
            'propertyIds' => [],
            'propertyStaticValueIds' => [],
        ];
        if ($this->onlyAvailableFilters) {
            $object = Object::findById($this->objectId);
            if (!is_null($object) && isset($this->currentSelections['last_category_id'])) {

                $cacheKey = 'FilterWidget: ' . $object->id . ':' . $this->currentSelections['last_category_id'] . ':'
                    . Json::encode($this->currentSelections['properties']);
                $data = Yii::$app->cache->get($cacheKey);
                if ($data === false) {
                    $query = new Query();
                    $query = $query->select($object->categories_table_name . '.object_model_id')
                        ->distinct()
                        ->from($object->categories_table_name)
                        ->where(['category_id' => $this->currentSelections['last_category_id']]);

                    if (count($this->currentSelections['properties']) > 0) {
                        foreach ($this->currentSelections['properties'] as $property_id => $values) {
                            $joinTableName = 'OSVJoinTable'.$property_id;
                            $query->join(
                                'JOIN',
                                ObjectStaticValues::tableName() . ' '.$joinTableName,
                                $joinTableName.'.object_id = :objectId AND '
                                . $joinTableName.'.object_model_id = ' . $object->categories_table_name . '.object_model_id  ',
                                [
                                    ':objectId' => $object->id,
                                ]
                            );


                            $query->andWhere(['in', '`'.$joinTableName.'`.`property_static_value_id`', $values]);
                        }
                    }


                    $ids = $query->column();
                    $query = null;
                    $data['propertyStaticValueIds'] = ObjectStaticValues::find()
                        ->select('property_static_value_id')
                        ->distinct()
                        ->where(['object_id' => $object->id, 'object_model_id' => $ids])
                        ->column();
                    $ids = null;
                    $data['propertyIds'] = PropertyStaticValues::find()
                        ->select('property_id')
                        ->distinct()
                        ->where(['id' => $data['propertyStaticValueIds'], 'dont_filter' => 0])
                        ->column();
                    Yii::$app->cache->set(
                        $cacheKey,
                        $data,
                        86400,
                        new TagDependency(
                            [
                                'tags' => [
                                    \devgroup\TagDependencyHelper\ActiveRecordHelper::getCommonTag($object->object_class)
                                ],
                            ]
                        )
                    );
                    $object = null;
                }
            }
        }

        $this->possibleSelections = [];

        $groups = PropertyGroup::getForObjectId($this->objectId);

        foreach ($groups as $group) {

            if ($this->onlyGroupsIds !== null) {
                if (in_array($group->id, $this->onlyGroupsIds) === false) {
                    // skip this group
                    continue;
                }
            }

            if ($group->is_internal) {
                continue;
            }
            $this->possibleSelections[$group->id] = [
                'group' => $group,
                'selections' => [],
                'static_selections' => [],
                'dynamic_selections' => [],
            ];
            $props = Property::getForGroupId($group->id);
            foreach ($props as $p) {

                if ($this->onlyAvailableFilters && !in_array($p->id, $data['propertyIds'])) {
                    if ($this->disableInsteadOfHide === false) {
                        continue;
                    }
                }
                if ($p->dont_filter) {
                    continue;
                }
                if ($p->has_static_values) {
                    $propertyStaticValues = PropertyStaticValues::getValuesForPropertyId($p->id);
                    foreach ($propertyStaticValues as $key => $propertyStaticValue) {

                        if ($propertyStaticValue['dont_filter']) {
                            unset($propertyStaticValues[$key]);
                        }
                    }
                    if ($this->onlyAvailableFilters) {
                        foreach ($propertyStaticValues as $key => $propertyStaticValue) {

                            if (!in_array($propertyStaticValue['id'], $data['propertyStaticValueIds'])) {
                                if ($this->disableInsteadOfHide === true) {
                                    $this->disabled_ids[]=$propertyStaticValue['id'];
                                } else {
                                    unset($propertyStaticValues[$key]);
                                }
                            }
                        }
                    }

                    $this->possibleSelections[$group->id]['static_selections'][$p->id] = $propertyStaticValues;
                } elseif ($p->is_column_type_stored && $p->value_type == 'NUMBER') {
                    $this->possibleSelections[$group->id]['dynamic_selections'][] = $p->id;
                }

            }
            if (count($this->possibleSelections[$group->id]) === 0) {
                unset($this->possibleSelections[$group->id]);
            }
        }
    }
}
