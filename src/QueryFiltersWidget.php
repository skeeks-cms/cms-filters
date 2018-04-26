<?php
/**
 * @author Semenov Alexander <semenov@skeeks.com>
 * @link http://skeeks.com/
 * @copyright 2010 SkeekS (СкикС)
 * @date 25.05.2015
 */

namespace skeeks\cms\queryfilters;

use skeeks\cms\helpers\PaginationConfig;
use skeeks\cms\IHasModel;
use skeeks\cms\queryfilters\filters\NumberFilterField;
use skeeks\cms\queryfilters\filters\StringFilterField;
use skeeks\cms\widgets\DualSelect;
use skeeks\yii2\config\ConfigBehavior;
use skeeks\yii2\config\ConfigTrait;
use skeeks\yii2\config\DynamicConfigModel;
use skeeks\yii2\form\fields\TextField;
use skeeks\yii2\form\fields\WidgetField;
use yii\base\Event;
use yii\base\Model;
use yii\base\Widget;
use yii\data\ActiveDataProvider;
use yii\data\DataProviderInterface;
use yii\db\ActiveRecord;
use yii\db\ColumnSchema;
use yii\helpers\ArrayHelper;
use yii\widgets\ActiveForm;

/**
 * @property string                $modelClassName; название класса модели с которой идет работа
 * @property DataProviderInterface $dataProvider; готовый датапровайдер с учетом настроек виджета
 * @property array                 $resultColumns; готовый конфиг для построения колонок
 * @property PaginationConfig      $paginationConfig;
 *
 * Class ShopProductFiltersWidget
 * @package skeeks\cms\cmsWidgets\filters
 */
class QueryFiltersWidget extends Widget
{
    use ConfigTrait;

    /**
     * @var string
     */
    public $viewFile = '@skeeks/cms/queryfilters/views/filters';

    /**
     * @var ActiveDataProvider
     */
    public $dataProvider;

    /**
     * @var array по умолчанию включенные колонки
     */
    public $visibleFilters = [];

    /**
     * @var array
     */
    public $configBehaviorData = [];

    /**
     * @var bool генерировать фильтры автоматически
     */
    public $isEnabledAutoFilters = true;

    /**
     * @var IHasModel|array|DynamicConfigModel
     */
    public $filtersModel;

    /**
     * @var array
     */
    public $wrapperOptions = [];

    /**
     * @var array
     */
    public $activeForm = [
        //'class' => ActiveForm::class
    ];

    public function behaviors()
    {
        return ArrayHelper::merge(parent::behaviors(), [
            ConfigBehavior::class => ArrayHelper::merge([
                'class'       => ConfigBehavior::class,
                'configModel' => [
                    'fields'           => [
                        'visibleFilters' => [
                            'class'           => WidgetField::class,
                            'widgetClass'     => DualSelect::class,
                            'widgetConfig'    => [
                                'visibleLabel' => \Yii::t('skeeks/cms', 'Display columns'),
                                'hiddenLabel'  => \Yii::t('skeeks/cms', 'Hidden columns'),
                            ],
                            'on beforeRender' => function ($e) {
                                $widgetField = $e->sender;
                                $widgetField->widgetConfig['items'] = ArrayHelper::getValue(
                                    \Yii::$app->controller->getCallableData(),
                                    'availableColumns'
                                );
                            },
                        ],
                    ],
                    'attributeDefines' => [
                        'visibleFilters',
                    ],
                    'attributeLabels'  => [
                        'visibleFilters' => 'Отображаемые фильтры',
                    ],
                    'rules'            => [
                        ['visibleFilters', 'safe'],
                    ],
                ],
            ], (array)$this->configBehaviorData),
        ]);
    }

    /**
     *
     */
    public function init()
    {
        $defaultFiltersModel = [
            'class' => DynamicConfigModel::class,
            'formName' => 'f' . $this->id
        ];

        //Автомтическое конфигурирование колонок
        $this->_initAutoFilters();

        $defaultFiltersModel = ArrayHelper::merge((array)$this->_autoDynamicModelData, $defaultFiltersModel);

        $this->filtersModel = ArrayHelper::merge($defaultFiltersModel, (array)$this->filtersModel);
        $this->filtersModel = \Yii::createObject($this->filtersModel);

        $this->filtersModel->load(\Yii::$app->request->get());

        $defaultActiveForm = [
            'action' => [''],
            'method' => 'get',
            //'layout' => 'horizontal',
            'class'  => ActiveForm::class,
        ];

        $this->activeForm = ArrayHelper::merge($defaultActiveForm, $this->activeForm);

        parent::init();

        //Применение включенных/выключенных фильтров
        $this->_applyFilters();
    }

    public function run()
    {
        $this->wrapperOptions['id'] = $this->id;

        //Only visibles
        /*if ($this->filtersModel->builderFields()) {
            foreach ($this->filtersModel->builderFields() as $key => $field) {
                if (isset($field['on apply'])) {

                }
            }
        }*/

        $builder = new \skeeks\yii2\form\Builder([
            'models'     => $this->filtersModel->builderModels(),
            'model'      => $this->filtersModel,
            'fields'     => $this->filtersModel->builderFields(),
        ]);

        if ($builder->fields) {

            foreach ($builder->fields as $field)
            {
                $field->trigger('apply', new QueryFiltersEvent([
                    'field' => $field,
                    'widget' => $this,
                    'dataProvider' => $this->dataProvider,
                    'query' => $this->dataProvider->query,
                ]));
            }
        }

        $this->trigger('apply', new QueryFiltersEvent([
            'widget' => $this,
            'dataProvider' => $this->dataProvider,
            'query' => $this->dataProvider->query,
        ]));

        return $this->render($this->viewFile, [
            'builder' => $builder
        ]);
    }


    protected function applyColumns()
    {
        $result = [];
        //Есть логика включенных выключенных колонок
        if ($this->visibleFilters && $this->columns) {

            foreach ($this->visibleColumns as $key) {
                $result[$key] = ArrayHelper::getValue($this->columns, $key);
            }

            /*foreach ($this->_resultColumns as $key => $config) {
                $config['visible'] = false;
                $this->_resultColumns[$key] = $config;
            }*/

            /*$result = ArrayHelper::merge($result, $this->_resultColumns);
            $this->_resultColumns = $result;*/
            $this->columns = $result;
        }

        return $this;
    }


    private $_autoDynamicModelData = [];

    /**
     * This function tries to guess the columns to show from the given data
     * if [[columns]] are not explicitly specified.
     */
    protected function _initAutoFilters()
    {
        //Если автоопределение колонок не включено
        if (!$this->isEnabledAutoFilters) {
            return $this;
        }

        if (!$this->dataProvider) {
            return $this;
        }

        $dataProvider = clone $this->dataProvider;
        $models = $dataProvider->getModels();


        /**
         * @var $model Model
         * @var $model ActiveRecord
         */
        $model = reset($models);



        $result = [];

        $result['attributeDefines'] = $model->attributes();
        $result['attributeLabels'] = $model->attributeLabels();

        $rules = [];
        $fields = [];

        if ($model instanceof ActiveRecord) {
            foreach ($model::getTableSchema()->columns as $key => $column)
            {
                if (in_array($column->type, ['string', 'text'])) {
                    $fields[(string)$key] = [
                        'class' => StringFilterField::class,
                    ];

                    $rules[] = [(string)$key, 'safe'];
                } else if (in_array($column->type, ['integer', 'decimal'])) {
                    $fields[(string)$key] = [
                        'class' => NumberFilterField::class,
                    ];

                    $rules[] = [(string)$key, 'safe'];
                }

            }
        } elseif (is_array($model) || is_object($model)) {
            foreach ($model as $name => $value) {
                if ($value === null || is_scalar($value) || is_callable([$value, '__toString'])) {
                    $fields[(string)$name] = [
                        'class' => StringFilterField::class,
                    ];

                    $rules[] = [(string)$name, 'safe'];
                }
            }
        }

        $result['rules'] = $rules;
        $result['fields'] = $fields;
        $this->_autoDynamicModelData = $result;

        return $this;
    }

    protected function _applyFilters()
    {
        $result = [];
        $fields = $this->filtersModel->builderFields();

        //Есть логика включенных выключенных колонок
        if ($this->visibleFilters && $fields) {

            foreach ($this->visibleFilters as $key) {
                $result[$key] = ArrayHelper::getValue($fields, $key);
            }
        }

        if ($result) {
            $this->filtersModel->setFields($result);
        }

        return $this;
    }


    /**
     * Данные необходимые для редактирования компонента, при открытии нового окна
     * @return array
     */
    public function getEditData()
    {
        return [
            'callAttributes'   => $this->callAttributes,
            'availableColumns' => $this->filtersModel->attributeLabels(),
        ];
    }
}