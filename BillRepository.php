<?php

namespace Bills;

use Bill;
use InvalidArgumentException;
use BillableItems\BillableItemRepository;
use TicketAssignees\TicketAssigneeRepository;
use Common\Repository\BaseRepository;

class BillRepository extends BaseRepository
{
    private BillableItemRepository $billable_item_repository;
    private TicketAssigneeRepository $ticket_assignee_repository;

    public function __construct(
        BillableItemRepository $billable_item_repository,
        TicketAssigneeRepository $ticket_assignee_repository
    )
    {
        $this->billable_item_repository = $billable_item_repository;
        $this->ticket_assignee_repository = $ticket_assignee_repository;

        parent::__construct();
    }

    /**
     *
     * @return string
     */
    protected function eloquent(): string
    {
        return Bill::class;
    }

    /**
     *
     * @param int $id
     * @param array $columns
     * @return Bill
     * @throws InvalidArgumentException
     */
    public function getById(int $id, array $columns = ['*']): Bill
    {
        $item = Bill::select($columns)->find($id);

        if (!$item) {
            throw new InvalidArgumentException('Bill with #' . $item . ' not found');
        }

        return $item;
    }

    /**
     *
     * @param int $id
     * @return bool
     * @throws Exception
     */
    public function delete(int $id): bool
    {
        if (is_int($id)) {
            $item = $this->getById($id);
        }

        if (!($item instanceof Bill)) {
            throw new Exception();
        }

        $this->billable_item_repository->deleteByBillId($item->id);
        $this->ticket_assignee_repository->deleteByBillId($item->id);

        return $item->delete();
    }

    /**
     *
     * @param int|Bill $item
     * @param string $relation
     * @param array $relation_data
     * @return array
     * @throws Exception
     */
    public function createManyRelationEntities ($item, string $relation, array $relation_data = []): array
    {
        if (is_int($item)) {
            $item = $this->getById($item);
        }

        if (!($item instanceof Bill)) {
            throw new Exception();
        }
        
        if (!method_exists(Bill::class, $relation)) {
            throw new Exception();
        }

        $instances = $item->{$relation}()->createMany($relation_data);

        return $instances;
    }

    /**
	 * Destroy the models for the given IDs.
	 *
	 * @param  array|int  $ids
	 * @return int
	 */
    public function destroy($ids): int
    {
        $result = 0;

        if (is_int($ids)) {
            $item = $this->delete($ids);
            $result = 1;
        }

        if (is_array($ids)) {
            foreach ($ids as $value) {
                $this->delete((int)$value);
                $result++;
            }
        }

        return $result;
    }

}