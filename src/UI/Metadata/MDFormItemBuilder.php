<?php

namespace srag\Plugins\Opencast\UI\Metadata;

use DateTime;
use DateTimeImmutable;
use ILIAS\DI\Container;
use ILIAS\Refinery\Custom\Transformation;
use ILIAS\Refinery\Factory as RefineryFactory;
use ILIAS\UI\Component\Input\Field\Input;
use ILIAS\UI\Factory as UIFactory;
use ilPlugin;
use srag\Plugins\Opencast\Model\Metadata\Config\Event\MDFieldConfigEventAR;
use srag\Plugins\Opencast\Model\Metadata\Config\Event\MDFieldConfigEventRepository;
use srag\Plugins\Opencast\Model\Metadata\Config\MDFieldConfigAR;
use srag\Plugins\Opencast\Model\Metadata\Config\MDFieldConfigRepository;
use srag\Plugins\Opencast\Model\Metadata\Definition\MDCatalogue;
use srag\Plugins\Opencast\Model\Metadata\Definition\MDDataType;
use srag\Plugins\Opencast\Model\Metadata\Definition\MDFieldDefinition;
use srag\Plugins\Opencast\Model\Metadata\Helper\MDParser;
use srag\Plugins\Opencast\Model\Metadata\Helper\MDPrefiller;
use srag\Plugins\Opencast\Model\Metadata\Metadata;
use xoctException;

class MDFormItemBuilder
{

    const LABEL_PREFIX = 'md_';

    /**
     * @var UIFactory
     */
    protected $ui_factory;
    /**
     * @var MDCatalogue
     */
    protected $md_catalogue;
    /**
     * @var MDPrefiller
     */
    protected $prefiller;
    /**
     * @var MDFieldConfigRepository
     */
    private $md_conf_repository;
    /**
     * @var RefineryFactory
     */
    private $refinery_factory;
    /**
     * @var MDParser
     */
    private $MDParser;
    /**
     * @var ilPlugin
     */
    private $plugin;
    /**
     * @var Container
     */
    private $dic;

    public function __construct(MDCatalogue             $md_catalogue,
                                MDFieldConfigRepository $repository,
                                MDPrefiller             $prefiller,
                                UIFactory               $ui_factory,
                                RefineryFactory         $refinery_factory,
                                MDParser                $MDParser,
                                ilPlugin                $plugin,
                                Container               $dic)
    {
        $this->ui_factory = $ui_factory;
        $this->md_catalogue = $md_catalogue;
        $this->prefiller = $prefiller;
        $this->md_conf_repository = $repository;
        $this->refinery_factory = $refinery_factory;
        $this->MDParser = $MDParser;
        $this->plugin = $plugin;
        $this->dic = $dic;
    }

    public function create_section(bool $as_admin): Input
    {
        return $this->ui_factory->input()->field()->section($this->create_items($as_admin), $this->plugin->txt('metadata'))
            ->withAdditionalTransformation($this->transformation());
    }

    /**
     * @return Input[]
     * @throws xoctException
     */
    public function create_items(bool $as_admin): array
    {
        $form_elements = [];
        $MDFieldConfigARS = $this->md_conf_repository->getAllEditable($as_admin);
        array_walk($MDFieldConfigARS, function (MDFieldConfigAR $md_field_config) use (&$form_elements) {
            // TODO: visible for permission!
            $key = $this->prefixPostVar($md_field_config->getFieldId());
            $form_elements[$key] = $this->buildFormElementForMDField($md_field_config,
                $this->prefiller->getPrefillValue($md_field_config->getPrefill()));
        });
        return $form_elements;
    }

    public function update_section(Metadata $existing_metadata, bool $as_admin): Input
    {
        $form_elements = [];
        $MDFieldConfigARS = $this->md_conf_repository->getAll($as_admin);
        array_walk($MDFieldConfigARS, function (MDFieldConfigAR $md_field_config) use (&$form_elements, $existing_metadata) {
            $key = $this->prefixPostVar($md_field_config->getFieldId());
            $form_elements[$key] = $this->buildFormElementForMDField($md_field_config,
                $existing_metadata->getField($md_field_config->getFieldId())->getValue());
        });
        return $this->ui_factory->input()->field()->section($form_elements, $this->plugin->txt('metadata'))
            ->withAdditionalTransformation($this->transformation());
    }

    public function schedule_section(bool $as_admin): Input
    {
        $form_elements = [];
        $MDFieldConfigARS = array_filter($this->md_conf_repository->getAllEditable($as_admin), function (MDFieldConfigEventAR $fieldConfigAR) {
            // start date is part of scheduling and location has a special input field
            return !in_array($fieldConfigAR->getFieldId(),
                [MDFieldDefinition::F_START_DATE, MDFieldDefinition::F_LOCATION]);
        });
        array_walk($MDFieldConfigARS, function (MDFieldConfigEventAR $md_field_config) use (&$form_elements) {
            $key = $this->prefixPostVar($md_field_config->getFieldId());
            $form_elements[$key] = $this->buildFormElementForMDField($md_field_config,
                $this->prefiller->getPrefillValue($md_field_config->getPrefill()));
        });
        return $this->ui_factory->input()->field()->section($form_elements, $this->plugin->txt('metadata'))
            ->withAdditionalTransformation($this->transformation());
    }

    public function update_scheduled_section(Metadata $existing_metadata, bool $as_admin): Input
    {
        $form_elements = [];
        $MDFieldConfigARS = array_filter($this->md_conf_repository->getAll($as_admin), function (MDFieldConfigEventAR $fieldConfigAR) {
            // start date is part of scheduling and location has a special input field
            return !in_array($fieldConfigAR->getFieldId(),
                [MDFieldDefinition::F_START_DATE, MDFieldDefinition::F_LOCATION]);
        });
        array_walk($MDFieldConfigARS, function (MDFieldConfigEventAR $md_field_config) use (&$form_elements, $existing_metadata) {
            $key = $this->prefixPostVar($md_field_config->getFieldId());
            $form_elements[$key] = $this->buildFormElementForMDField($md_field_config,
                $existing_metadata->getField($md_field_config->getFieldId())->getValue());
        });
        return $this->ui_factory->input()->field()->section($form_elements, $this->plugin->txt('event_metadata'))
            ->withAdditionalTransformation($this->transformation());
    }

    /**
     * @throws xoctException
     */
    public function buildFormElementForMDField(MDFieldConfigAR $fieldConfigAR, $value): Input
    {
        $md_definition = $this->md_catalogue->getFieldById($fieldConfigAR->getFieldId());
        switch ($md_definition->getType()->getTitle()) {
            case MDDataType::TYPE_TEXT:
                $field = $this->ui_factory->input()->field()->text($fieldConfigAR->getTitle($this->dic->language()->getLangKey()));
                break;
            case MDDataType::TYPE_TEXT_ARRAY:
                $field = $this->ui_factory->input()->field()->text($fieldConfigAR->getTitle($this->dic->language()->getLangKey()))
                    ->withAdditionalTransformation($this->refinery_factory->custom()->transformation(function (string $value) {
                        return explode(',', $value);
                    }))->withValue(''); // can be removed if this is fixed: https://mantis.ilias.de/view.php?id=32104
                break;
            case MDDataType::TYPE_TEXT_LONG:
                $field = $this->ui_factory->input()->field()->textarea($fieldConfigAR->getTitle($this->dic->language()->getLangKey()));
                break;
            case MDDataType::TYPE_TIME:
            case MDDataType::TYPE_DATETIME:
                $field = $this->ui_factory->input()->field()->dateTime($fieldConfigAR->getTitle($this->dic->language()->getLangKey()))->withUseTime(true);
                break;
            default:
                throw new xoctException(xoctException::INTERNAL_ERROR,
                    'Unknown MDDataType: ' . $md_definition->getType()->getTitle());
        }
        $field = $field
            ->withRequired($fieldConfigAR->isRequired())
            ->withDisabled($fieldConfigAR->isReadOnly());
        return $value ? $field->withValue($this->formatValue($value, $md_definition)) : $field;
    }


    private function formatValue($value, MDFieldDefinition $md_definition)
    {
        switch ($md_definition->getType()->getTitle()) {
            case MDDataType::TYPE_DATETIME:
                /** @var $value DateTimeImmutable */
                return $value instanceof DateTimeImmutable ? $value->format('Y-m-d H:i:s') : $value;
            case MDDataType::TYPE_TEXT_ARRAY:
                return is_array($value) ? implode(',', $value) : $value;
            default:
                return $value;
        }
    }

    public function prefixPostVar(string $label): string
    {
        return self::LABEL_PREFIX . $label;
    }

    public function transformation(): Transformation
    {
        return $this->refinery_factory->custom()->transformation(function ($vs) {
            // todo: remove this ugly instance check (maybe create subclasses MDEventFormItemBuilder and MDSeriesFormItemBuilder)
            $vs['object'] = ($this->md_conf_repository instanceof MDFieldConfigEventRepository) ?
                $this->MDParser->parseFormDataEvent($vs)
                : $this->MDParser->parseFormDataSeries($vs);
            return $vs;
        });
    }

    public function parser(): MDParser
    {
        return $this->MDParser;
    }
}