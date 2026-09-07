<?php

declare(strict_types=1);

namespace Drupal\ai_example_tools\Plugin\AiFunctionCall;

use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Plugin\Context\ContextDefinition;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\ai\Attribute\FunctionCall;
use Drupal\ai\Base\FunctionCallBase;
use Drupal\ai\Service\FunctionCalling\ExecutableFunctionCallInterface;
use Drupal\ai\Service\FunctionCalling\FunctionCallInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Reports content and taxonomy counts for the site.
 *
 * A read-only AiFunctionCall — gives the model a real number instead of
 * letting it guess or hallucinate one. Optionally scoped to one content type.
 */
#[FunctionCall(
  id: 'ai_example_tools:site_content_statistics',
  function_name: 'ai_example_tools_site_content_statistics',
  name: 'Site Content Statistics',
  description: 'Reports how many content items of each type exist on the site (published vs. unpublished), and how many terms each taxonomy vocabulary has. Optionally scoped to a single content type.',
  group: 'content_tools',
  context_definitions: [
    'content_type' => new ContextDefinition(
      data_type: 'string',
      label: new TranslatableMarkup('Content type'),
      description: new TranslatableMarkup('Optional machine name of a single content type to report on, e.g. "article". Leave empty to report on all content types.'),
      required: FALSE,
    ),
  ],
)]
class SiteContentStatistics extends FunctionCallBase implements ExecutableFunctionCallInterface {

  /**
   * The entity type manager.
   */
  protected EntityTypeManagerInterface $entityTypeManager;

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container, array $configuration, $plugin_id, $plugin_definition): FunctionCallInterface|static {
    $instance = new static(
      $configuration,
      $plugin_id,
      $plugin_definition,
      $container->get('ai.context_definition_normalizer'),
    );
    $instance->entityTypeManager = $container->get('entity_type.manager');
    return $instance;
  }

  /**
   * {@inheritdoc}
   */
  public function execute(?object $object = NULL) {
    $filter_bundle = $this->getContextValue('content_type');

    $node_type_storage = $this->entityTypeManager->getStorage('node_type');
    if ($filter_bundle && !$node_type_storage->load($filter_bundle)) {
      throw new \Exception("The content type \"$filter_bundle\" does not exist.");
    }
    $bundles = $filter_bundle ? [$filter_bundle] : array_keys($node_type_storage->loadMultiple());

    $node_storage = $this->entityTypeManager->getStorage('node');
    $content_counts = [];
    foreach ($bundles as $bundle) {
      $published = (int) $node_storage->getQuery()
        ->accessCheck(FALSE)
        ->condition('type', $bundle)
        ->condition('status', 1)
        ->count()
        ->execute();
      $unpublished = (int) $node_storage->getQuery()
        ->accessCheck(FALSE)
        ->condition('type', $bundle)
        ->condition('status', 0)
        ->count()
        ->execute();
      $content_counts[$bundle] = [
        'published' => $published,
        'unpublished' => $unpublished,
        'total' => $published + $unpublished,
      ];
    }

    $vocabulary_storage = $this->entityTypeManager->getStorage('taxonomy_vocabulary');
    $term_storage = $this->entityTypeManager->getStorage('taxonomy_term');
    $vocabulary_counts = [];
    foreach (array_keys($vocabulary_storage->loadMultiple()) as $vid) {
      $vocabulary_counts[$vid] = (int) $term_storage->getQuery()
        ->accessCheck(FALSE)
        ->condition('vid', $vid)
        ->count()
        ->execute();
    }

    $lines = [];
    foreach ($content_counts as $bundle => $counts) {
      $lines[] = sprintf(
        '- %s: %d published, %d unpublished (%d total)',
        $bundle,
        $counts['published'],
        $counts['unpublished'],
        $counts['total'],
      );
    }
    $lines[] = '';
    $lines[] = 'Taxonomy terms:';
    foreach ($vocabulary_counts as $vid => $count) {
      $lines[] = sprintf('- %s: %d term(s)', $vid, $count);
    }

    $this->setOutput(implode("\n", $lines));
    $this->setStructuredOutput([
      'content' => $content_counts,
      'taxonomy' => $vocabulary_counts,
    ]);
  }

}
