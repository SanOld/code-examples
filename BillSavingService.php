<?php

declare(strict_types = 1);

namespace Bills\Services;

use App;
use Bill;
use module\Periods;
use TicketAssignees;
use Exception;
use Bills\BillRepository;
/**
 * Saves the configured options in the editor view for editing bill
 * Class PriceSaver
 * @package Places\Services
 */
class BillSavingService
{

    private BillRepository $bill_repository;

    public function __construct(BillRepository $bill_repository)
    {
        $this->bill_repository = $bill_repository;
    }

    /**
     * Create or update bill
     * @param array $data
     * @return int
     * @throws Exception
     */
    public function save(array $data = []): int
    {
        $id = aget($data, 'id');

        // Leave only meaningful assignees
        $assignees = array_filter(aget($data, 'assignees', []), function($e) {
            return array_filter($e);
        });

        $data['end_date'] = Periods::parseDate(aget($data, 'end_date'));
        $data['start_date'] = Periods::parseDate(aget($data, 'start_date'));

        if ($id) {
            $bill = $this->bill_repository->getById((int)$id);
            // Make impossible to change assignee when bill already has assigned
            if ($original_assignees = $bill->assignees->toArray()) {
                $assignees = $original_assignees;
            }

            $data['updated_at'] = Periods::parseDate(aget($data, 'updated_at'), now());
            $data['updated_by'] = App::getUserId();

            $bill = $this->bill_repository->store($data);

        } else {
            // Check assignees on creation only, on update they cannot be changed anyway

            if (empty($assignees)) {
                throw new Exception('Unable to create bill without assignees');
            }

            $data['created_at'] = Periods::parseDate(aget($data, 'created_at'), now());
            $data['created_by'] = App::getUserId();

            unset($data['id']);

            $bill = $this->bill_repository->store($data);
        }

        $this->updateAssignees($bill, $assignees);

        return (int)$bill->getKey();
    }

    /**
     * Update assignees in selected bill
     * @param Bill $bill
     * @param $assignees - array of IDs
     * @return bool
     */
    protected function updateAssignees(Bill $bill, array $assignees = []): bool
    {
        $bill->assignees()->delete();

        if ($assignees) {
            $assignee_model = new TicketAssignees();
            $assignee_columns = $assignee_model->getFillable();
            $bill_id = $bill->getKey();
            $insert = [];

            foreach ($assignees as $key => $assignee) {
                $assignee['bill_id'] = $bill_id;
                $insert[] = array_filter(array_only($assignee, $assignee_columns));
            }

            return TicketAssignees::insert($insert);
        }

        return true;
    }

}
