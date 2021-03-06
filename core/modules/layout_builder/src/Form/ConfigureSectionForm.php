<?php

namespace Drupal\layout_builder\Form;

use Drupal\Core\DependencyInjection\ClassResolverInterface;
use Drupal\Core\Entity\EntityInterface;
use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Form\SubformState;
use Drupal\Core\Layout\LayoutInterface;
use Drupal\Core\Layout\LayoutPluginManagerInterface;
use Drupal\Core\Plugin\PluginFormFactoryInterface;
use Drupal\Core\Plugin\PluginFormInterface;
use Drupal\Core\Plugin\PluginWithFormsInterface;
use Drupal\layout_builder\Controller\LayoutRebuildTrait;
use Drupal\layout_builder\LayoutTempstoreRepositoryInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Provides a form for configuring a layout section.
 *
 * @internal
 */
class ConfigureSectionForm extends FormBase {

  use AjaxFormHelperTrait;
  use LayoutRebuildTrait;

  /**
   * The layout tempstore repository.
   *
   * @var \Drupal\layout_builder\LayoutTempstoreRepositoryInterface
   */
  protected $layoutTempstoreRepository;

  /**
   * The plugin being configured.
   *
   * @var \Drupal\Core\Layout\LayoutInterface|\Drupal\Core\Plugin\PluginFormInterface
   */
  protected $layout;

  /**
   * The layout manager.
   *
   * @var \Drupal\Core\Layout\LayoutPluginManagerInterface
   */
  protected $layoutManager;

  /**
   * The plugin form manager.
   *
   * @var \Drupal\Core\Plugin\PluginFormFactoryInterface
   */
  protected $pluginFormFactory;

  /**
   * The entity.
   *
   * @var \Drupal\Core\Entity\EntityInterface
   */
  protected $entity;

  /**
   * The field delta.
   *
   * @var int
   */
  protected $delta;

  /**
   * Indicates whether the section is being added or updated.
   *
   * @var bool
   */
  protected $isUpdate;

  /**
   * Constructs a new ConfigureSectionForm.
   *
   * @param \Drupal\layout_builder\LayoutTempstoreRepositoryInterface $layout_tempstore_repository
   *   The layout tempstore repository.
   * @param \Drupal\Core\Layout\LayoutPluginManagerInterface $layout_manager
   *   The layout manager.
   * @param \Drupal\Core\DependencyInjection\ClassResolverInterface $class_resolver
   *   The class resolver.
   * @param \Drupal\Core\Plugin\PluginFormFactoryInterface $plugin_form_manager
   *   The plugin form manager.
   */
  public function __construct(LayoutTempstoreRepositoryInterface $layout_tempstore_repository, LayoutPluginManagerInterface $layout_manager, ClassResolverInterface $class_resolver, PluginFormFactoryInterface $plugin_form_manager) {
    $this->layoutTempstoreRepository = $layout_tempstore_repository;
    $this->layoutManager = $layout_manager;
    $this->classResolver = $class_resolver;
    $this->pluginFormFactory = $plugin_form_manager;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container) {
    return new static(
      $container->get('layout_builder.tempstore_repository'),
      $container->get('plugin.manager.core.layout'),
      $container->get('class_resolver'),
      $container->get('plugin_form.factory')
    );
  }

  /**
   * {@inheritdoc}
   */
  public function getFormId() {
    return 'layout_builder_configure_section';
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state, EntityInterface $entity = NULL, $delta = NULL, $plugin_id = NULL) {
    $this->entity = $entity;
    $this->delta = $delta;
    $this->isUpdate = is_null($plugin_id);

    $configuration = [];
    if ($this->isUpdate) {
      /** @var \Drupal\layout_builder\Field\LayoutSectionItemInterface $field */
      $field = $this->entity->layout_builder__layout->get($this->delta);
      $plugin_id = $field->layout;
      $configuration = $field->layout_settings;
    }
    $this->layout = $this->layoutManager->createInstance($plugin_id, $configuration);

    $form['#tree'] = TRUE;
    $form['layout_settings'] = [];
    $subform_state = SubformState::createForSubform($form['layout_settings'], $form, $form_state);
    $form['layout_settings'] = $this->getPluginForm($this->layout)->buildConfigurationForm($form['layout_settings'], $subform_state);

    $form['actions']['submit'] = [
      '#type' => 'submit',
      '#value' => $this->isUpdate ? $this->t('Update') : $this->t('Add section'),
      '#button_type' => 'primary',
    ];
    if ($this->isAjax()) {
      $form['actions']['submit']['#ajax']['callback'] = '::ajaxSubmit';
    }

    return $form;
  }

  /**
   * {@inheritdoc}
   */
  public function validateForm(array &$form, FormStateInterface $form_state) {
    $subform_state = SubformState::createForSubform($form['layout_settings'], $form, $form_state);
    $this->getPluginForm($this->layout)->validateConfigurationForm($form['layout_settings'], $subform_state);
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state) {
    // Call the plugin submit handler.
    $subform_state = SubformState::createForSubform($form['layout_settings'], $form, $form_state);
    $this->getPluginForm($this->layout)->submitConfigurationForm($form['layout_settings'], $subform_state);

    $plugin_id = $this->layout->getPluginId();
    $configuration = $this->layout->getConfiguration();

    /** @var \Drupal\layout_builder\Field\LayoutSectionItemListInterface $field_list */
    $field_list = $this->entity->layout_builder__layout;
    if ($this->isUpdate) {
      $field = $field_list->get($this->delta);
      $field->layout = $plugin_id;
      $field->layout_settings = $configuration;
    }
    else {
      $field_list->addItem($this->delta, [
        'layout' => $plugin_id,
        'layout_settings' => $configuration,
        'section' => [],
      ]);
    }

    $this->layoutTempstoreRepository->set($this->entity);
    $form_state->setRedirectUrl($this->entity->toUrl('layout-builder'));
  }

  /**
   * {@inheritdoc}
   */
  protected function successfulAjaxSubmit(array $form, FormStateInterface $form_state) {
    return $this->rebuildAndClose($this->entity);
  }

  /**
   * Retrieves the plugin form for a given layout.
   *
   * @param \Drupal\Core\Layout\LayoutInterface $layout
   *   The layout plugin.
   *
   * @return \Drupal\Core\Plugin\PluginFormInterface
   *   The plugin form for the layout.
   */
  protected function getPluginForm(LayoutInterface $layout) {
    if ($layout instanceof PluginWithFormsInterface) {
      return $this->pluginFormFactory->createInstance($layout, 'configure');
    }

    if ($layout instanceof PluginFormInterface) {
      return $layout;
    }

    throw new \InvalidArgumentException(sprintf('The "%s" layout does not provide a configuration form', $layout->getPluginId()));
  }

}
