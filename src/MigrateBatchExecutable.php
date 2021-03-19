<?php

namespace Drupal\dgi_migrate;

use Drupal\migrate_tools\MigrateExecutable;
use Drupal\migrate\Event\MigrateEvents;
use Drupal\migrate\Event\MigrateImportEvent;
use Drupal\migrate\Event\MigratePostRowSaveEvent;
use Drupal\migrate\Event\MigratePreRowSaveEvent;
use Drupal\migrate\Exception\RequirementsException;
use Drupal\migrate\Plugin\MigrateIdMapInterface;
use Drupal\migrate\Plugin\MigrationInterface;
use Drupal\migrate\MigrateMessageInterface;
use Drupal\migrate\MigrateSkipRowException;
use Drupal\Core\DependencyInjection\DependencySerializationTrait;
use Drupal\migrate\Row;

/**
 * Migration executable to run as fully queued batch.
 */
class MigrateBatchExecutable extends MigrateExecutable {

  use DependencySerializationTrait;

  /**
   * The queue to deal with.
   *
   * @var \Drupal\Core\Queue\QueueInterface
   */
  protected $queue;

  /**
   * {@inheritdoc}
   */
  public function __construct(MigrationInterface $migration, MigrateMessageInterface $message, array $options = []) {
    parent::__construct($migration, $message, $options);

    $queue_name = "dgi_migrate__batch_queue__{$migration->id()}";
    $this->queue = \Drupal::queue($queue_name, TRUE);
  }

  /**
   * Prepare a batch array for execution for the given migration.
   *
   * @return array
   *   A batch array with operations and the like.
   *
   * @throws \Exception
   *   If the migration could not be enqueued successfully.
   */
  public function prepareBatch() {
    $result = $this->enqueue();
    if ($result === MigrationInterface::RESULT_COMPLETED) {
      return [
        'title' => $this->t('Running migration: @migration', [
          '@migration' => $this->migration->id(),
        ]),
        'operations' => [
          [[$this, 'processBatch'], []],
        ],
        'finished' => [$this, 'finishBatch'],
      ];
    }
    else {
      throw new \Exception('Migration failed.');
    }
  }

  /**
   * Batch finished callback.
   */
  public function finishBatch($success, $results, $ops, $interval) {
    $this->queue->deleteQueue();
    $this->getEventDispatcher()->dispatch(MigrateEvents::POST_IMPORT, new MigrateImportEvent($this->migration, $this->message));
    $this->migration->setStatus(MigrationInterface::STATUS_IDLE);

  }

  /**
   * Populate the target queue with the rows of the given migration.
   *
   * @return int
   *   One of the MigrationInterface::RESULT_* constants representing the state
   *   of queueing.
   */
  protected function enqueue() {
    // Only begin the import operation if the migration is currently idle.
    if ($this->migration->getStatus() !== MigrationInterface::STATUS_IDLE) {
      $this->message->display($this->t('Migration @id is busy with another operation: @status',
        [
          '@id' => $this->migration->id(),
          // XXX: Copypasta.
          // @See https://git.drupalcode.org/project/drupal/-/blob/154038f1401583a30e0ea7d9c19db02f37b10943/core/modules/migrate/src/MigrateExecutable.php#L156
          //phpcs:ignore Drupal.Semantics.FunctionT.NotLiteralString
          '@status' => $this->t($this->migration->getStatusLabel()),
        ]), 'error');
      return MigrationInterface::RESULT_FAILED;
    }
    $this->getEventDispatcher()->dispatch(MigrateEvents::PRE_IMPORT, new MigrateImportEvent($this->migration, $this->message));

    // Knock off migration if the requirements haven't been met.
    try {
      $this->migration->checkRequirements();
    }
    catch (RequirementsException $e) {
      $this->message->display(
        $this->t(
          'Migration @id did not meet the requirements. @message @requirements',
          [
            '@id' => $this->migration->id(),
            '@message' => $e->getMessage(),
            '@requirements' => $e->getRequirementsString(),
          ]
        ),
        'error'
      );

      return MigrationInterface::RESULT_FAILED;
    }

    $this->migration->setStatus(MigrationInterface::STATUS_IMPORTING);
    $source = $this->getSource();

    try {
      $source->rewind();
    }
    catch (\Exception $e) {
      $this->message->display(
        $this->t('Migration failed with source plugin exception: @e', ['@e' => $e->getMessage()]), 'error');
      $this->migration->setStatus(MigrationInterface::STATUS_IDLE);
      return MigrationInterface::RESULT_FAILED;
    }

    // XXX: Nuke it, just in case.
    $this->queue->deleteQueue();
    foreach ($source as $row) {
      $this->queue->createItem($row);
    }
    return MigrationInterface::RESULT_COMPLETED;
  }

  /**
   * The meat of processing a row.
   *
   * Perform the processing of a row and save it to the destination, if
   * applicable.
   *
   * @param \Drupal\migrate\Row $row
   *   The row to be processed.
   *
   * @return int
   *   One of the MigrationInterface::STATUS_* constants.
   */
  protected function processRowFromQueue(Row $row) {
    $id_map = $this->getIdMap();
    $this->sourceIdValues = $row->getSourceIdValues();

    try {
      $this->processRow($row);
      $save = TRUE;
    }
    catch (MigrateException $e) {
      $this->getIdMap()->saveIdMapping($row, [], $e->getStatus());
      $this->saveMessage($e->getMessage(), $e->getLevel());
      $save = FALSE;
    }
    catch (MigrateSkipRowException $e) {
      if ($e->getSaveToMap()) {
        $id_map->saveIdMapping($row, [], MigrateIdMapInterface::STATUS_IGNORED);
      }
      if ($message = trim($e->getMessage())) {
        $this->saveMessage($message, MigrationInterface::MESSAGE_INFORMATIONAL);
      }
      $save = FALSE;
    }

    if ($save) {
      try {
        $destination = $this->migration->getDestinationPlugin();
        $this->getEventDispatcher()->dispatch(MigrateEvents::PRE_ROW_SAVE, new MigratePreRowSaveEvent($this->migration, $this->message, $row));
        $destination_ids = $id_map->lookupDestinationIds($this->sourceIdValues);
        $destination_id_values = $destination_ids ? reset($destination_ids) : [];
        $destination_id_values = $destination->import($row, $destination_id_values);
        $this->getEventDispatcher()->dispatch(MigrateEvents::POST_ROW_SAVE, new MigratePostRowSaveEvent($this->migration, $this->message, $row, $destination_id_values));
        if ($destination_id_values) {
          // We do not save an idMap entry for config.
          if ($destination_id_values !== TRUE) {
            $id_map->saveIdMapping($row, $destination_id_values, $this->sourceRowStatus, $destination->rollbackAction());
          }
        }
        else {
          $id_map->saveIdMapping($row, [], MigrateIdMapInterface::STATUS_FAILED);
          if (!$id_map->messageCount()) {
            $message = $this->t('New object was not saved, no error provided');
            $this->saveMessage($message);
            $this->message->display($message);
          }
        }
      }
      catch (MigrateException $e) {
        $this->getIdMap()->saveIdMapping($row, [], $e->getStatus());
        $this->saveMessage($e->getMessage(), $e->getLevel());
      }
      catch (\Exception $e) {
        $this->getIdMap()->saveIdMapping($row, [], MigrateIdMapInterface::STATUS_FAILED);
        $this->handleException($e);
      }
    }

    $this->sourceRowStatus = MigrateIdMapInterface::STATUS_IMPORTED;

    // Check for memory exhaustion.
    if (($return = $this->checkStatus()) != MigrationInterface::RESULT_COMPLETED) {
      return $return;
    }

    // If anyone has requested we stop, return the requested result.
    if ($this->migration->getStatus() == MigrationInterface::STATUS_STOPPING) {
      $return = $this->migration->getInterruptionResult();
      $this->migration->clearInterruptionResult();
      return $return;
    }

  }

  /**
   * Batch operation callback.
   *
   * @param array|\DrushBatchContext $context
   *   Batch context.
   */
  public function processBatch(&$context) {
    $sandbox =& $context['sandbox'];

    if (!isset($sandbox['total'])) {
      $sandbox['current'] = 0;
      $sandbox['total'] = $this->queue->numberOfItems();
      if ($sandbox['total'] === 0) {
        return;
      }
    }

    $item = $this->queue->claimItem();
    if (!$item) {
      $context['results']['status'] = MigrationInterface::RESULT_COMPLETED;
      $context['message'] = $this->t('Queue empty...');
      return;
    }

    try {
      $this->processRowFromQueue($item->data);
      $this->queue->deleteItem($item);
      $context['finished'] = ++$sandbox['current'] / $sandbox['total'];
      $context['message'] = $this->t('Migration "@migration": @current/@total', [
        '@migration' => $this->migration->id(),
        '@current'   => $sandbox['current'],
        '@total'     => $sandbox['total'],
      ]);
    }
    catch (\Exception $e) {
      $context['results']['status'] = MigrationInterface::RESULT_FAILED;
      $context['message'] = strtr("Exception while processing :migration (:source_ids):\n:message\n:trace", [
        ':migration' => $this->migration->id(),
        ':source_ids' => implode(',', $this->sourceIdValues),
        ':message' => $e->getMessage(),
        ':trace' => $e->getTraceAsString(),
      ]);
    }
    finally {}
  }

}
