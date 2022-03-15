<?php

declare(strict_types=1);

namespace Bills;

use App;
use Bill;
use UserModel;
use Inputs;
use Illuminate\Database\Eloquent\Builder;
use Common\Repository\BaseRepository;

class BillsRepository extends BaseRepository
{

    protected function eloquent(): string
    {
        return Bill::class;
    }

    /**
     *
     * @param array $params
     * @return Builder
     */
    public function getBillsRecords(array $params = []): Builder
    {
        $user_id = aget($params, 'user_id');
        $with = aget($params, 'with') ?: [];
        $is_extended_mode = aget($params, 'is_extended_mode') ?: false;
        $user_companies = [];

        $records = Bill::with($with);

        // regular users on Bills can see bills assigned to them, their groups and companies,
        // and bills where they are managers

        $records->where(/** @var Bill $query **/function ($query) use ($user_id, $is_extended_mode) {
            $query->where(/** @var Bill $q **/function ($q) use ($user_id, $is_extended_mode) {
                    $q->assigneeIds($user_id, $is_extended_mode);
                })
                ->orWhere(/** @var Bill $q **/function ($q) use ($user_id) {
                    $q->managerIds($user_id);
                });
        });
        
        if ($is_extended_mode) {
            $user_companies = UserModel::getActiveCompanyIdsBasedOnURLRoute(Inputs::getRoute());
            $records->orWhere(/** @var Bill $query **/function ($query) use ($user_companies) {
                $query->whereIn('company_id', $user_companies);
            });
        }

        return $records;
    }

    /**
     *
     * @param string|null $id
     * @return Bill|null
     */
    public function getBillByIdWithBillableItems(?string $id): ?Bill
    {
        return Bill::with(['billable_items' => subquery_select(['bill_id', 'billable_type', 'billable_id']), 'manager', 'company', 'assignees'])->find($id);
    }

    /**
     * Returns billable_item ids gruped by billable_type.
     *
     * @param Bill $model
     * @return array
     */
    public function billableItemsIdsGroupedByType(Bill $model): array
    {
        $billable_items_list = [];
        $billable_items = $model->billable_items()->get();
        $billable_items_grouped_by_type = $billable_items->groupBy('billable_type')->toArray();

        foreach ($billable_items_grouped_by_type as $k => $v) {
            $billable_items_list[$k] = array_pluck($v, 'billable_id');
        }

        return $billable_items_list;
    }
    
}
