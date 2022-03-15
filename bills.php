<?php

declare(strict_types=1);

use tickets\TicketsFormatter;
use Bills\BillRepository;
use Bills\BillsRepository;
use Bills\Services\BillSavingService;
use BillableItems\BillableItemRepository;
use Statuses\StatusRepository;
use Illuminate\Database\Eloquent\Builder;

class ControllerBillsBills extends Controller
{

    use FormTrait;
    use ActiveSearchTrait;
    use AssigneeTrait;
    use TicketsFilterTrait;
    use UserDealerScopeTrait;
    use EntityAttributesTrait;
    use GridTrait {
        applyFilter as traitApplyFilter;
    }

    protected array $custom_columns = [
        'assignees'
    ];
    protected $rules = [
        'inputs' => [
            'selectors_id'         => JsValidator::NUMBER,
            'selectors_start_date' => 'date|min:6|max:20',
            'selectors_end_date'   => 'date|min:6|max:20'
        ],
        'editor' => [
            'start_date' => 'required|date|min:6|max:20',
            'end_date'   => 'required|date|min:6|max:20',
            'manager'    => 'required',
            'company'    => 'required',
            'name'       => 'required',
            'status_id'  => 'required'
        ]
    ];
    protected BillRepository $bill_repository;
    protected BillsRepository $bills_repository;
    protected BillableItemRepository $billable_item_repository;
    protected array $statuses;

    public function __construct(
        Registry $registry,
        BillRepository $bill_repository,
        BillsRepository $bills_repository,
        BillableItemRepository $billable_item_repository,
        StatusRepository $status_repository)
    {

        $this->statuses = $status_repository->getItemsByGroupName(Status::BILL_STATUS_GROUP)
            ->keyBy('status_id', 'name')
            ->transform(function($e) {
                return $e->name = lb($e->name);
            })
            ->toArray();

        $this->bill_repository = $bill_repository;
        $this->bills_repository = $bills_repository;
        $this->billable_item_repository = $billable_item_repository;

        parent::__construct($registry);
    }

    /**
     * Display list of view settings
     */
    public function index()
    {
        $this->document->title = 'Bills';
        $this->document->setMainTitle('Bills');
        $this->blade_template = 'bills/bills';

        $is_ajax = Request::isAjax();

        $this->data['available_attributes'] = $this->getAvailableAttributes(EavModel::ENTITY_BILL)
            ->toJson(JSON_NUMERIC_CHECK);
        $this->data['assignees'] = $this->getAssigneeUi([], ['class' => 'form-inline filter-inline']);
        $this->data['validators'] = $this->setupValidators($this->rules, Inputs::all());
        $this->data['editors'] = $this->getFormParts(['class' => 'form-inline']);
        $this->data['grid'] = $this->getGridUi();
        $this->data['is_ajax'] = $is_ajax;
        $this->data['params'] = Inputs::only(['user_id', 'is_extended_mode']);
        $this->data['statuses'] = $this->statuses;

        if (!$is_ajax) {
            $this->children = [
                'common/header',
                'common/footer'
            ];
        }

        $this->response->setOutput($this->render(true));
    }

    /**
     * Find record by ID or just render empty form to create one
     */
    public function item()
    {
        $this->document->title = 'Bill Editor';
        $this->document->setMainTitle('Bill Editor');
        $this->blade_template = 'bills/bill';

        $company_id = false;
        $id = Inputs::get('id', null);

        if ($id) {
            $model = $this->bill_repository->getById((int)$id);
            $company_id = $model->company_id;
        }

        $entity = (object) [
            'entity_id'  => $id,
            'company_id' => $company_id
        ];
        $attributes_params = $this->getEntityAttributeGroupsParameters(EavModel::ENTITY_BILL, $entity, 'bills', 'bills', 3);

        $this->data = $this->getBillData($id, [], json_encode($attributes_params['available_attributes_groups_list']));

        $this->data['available_attributes_groups_list'] = $attributes_params['available_attributes_groups_list'];
        $this->data['editable_attributes_groups_list'] = $attributes_params['editable_attributes_groups_list'];
        $this->data['visible_attributes_groups_list'] = $attributes_params['visible_attributes_groups_list'];
        $this->data['attributes'] = $attributes_params['attributes'];
        $data = $attributes_params['attribute_popup_data'];

        $this->data['attributes_popup'] = App::makeView('attributes.attributes_popup', $data)->render();

        $this->data['id'] = $id;
        $this->data['statuses'] = $this->statuses;

        $this->children = [
            'common/header',
            'common/footer'
        ];

        $this->response->setOutput($this->render(TRUE));
    }

   /**
    *
    * @param string|null $id
    * @param array $assignee_params
    * @param string $available_attributes
    * @return array
    */
    public function getBillData(?string $id, array $assignee_params = [], string $available_attributes = '{}'): array
    {
        $data = [];
        $assignees = [];
        $bill = null;
        $selection = null;

        $bill = $this->bills_repository->getBillByIdWithBillableItems($id);

        $billable_items_list = [];
        
        if ($bill) {
            $assignees = TicketAssignees::assigneeDetails($bill->assignees->toArray(), true);
            $selection = implode(',', (array_unique($bill->billable_items()->lists('id'))));

            $billable_items_list = $this->bills_repository->billableItemsIdsGroupedByType($bill);
        }

        $data['bill'] = $bill;
        $data['sender'] = $this->getFormParts(['class' => 'form-inline bill-params'], 'x', [1, 1]);
        $data['validators'] = $this->setupValidators($this->rules, Inputs::all());
        $data['assignees'] = $this->getAssigneeUi($assignees, array_merge(['labels' => 1, 'disable' => $assignees, 'required' => '* '], $assignee_params));
        $data['billable_items_list'] = $billable_items_list;

        $data['selectors'] = $this->getTicketsFilterUi(
            'selectors',
            ['filter' => 'Filter', 'reset' => 'Reset'],
            [],
            ['group_by_role' => true]
        );
        $data['roles_selectors'] = $this->getRolesFilterUi('selectors');

        $billable_items_params = array_merge([
            'uid'     => 'billable_items',
            'caption' => 'Billable Items',
            'bill_id' => $bill ? $bill->id : ''
            ], $data);

        $data['billable_items'] = App::makeView('bills/billable_item_records', $billable_items_params)->render();

        $data['grid'] = $this->getGridUi();

        return $data;
    }

    /**
     *
     * @return array
     */
    protected function dependencyOfColumnsAndRelations(): array
    {
        return [
            'assignees'  => ['assignees'],
            'created_by' => ['createdBy', ['user_id', 'firstname', 'lastname']],
            'updated_by' => ['updatedBy', ['user_id', 'firstname', 'lastname']],
            'manager_id' => ['manager', ['user_id', 'firstname', 'lastname']],
            'company_id' => ['company', ['id', 'name']],
        ];
    }

   /**
    *
    * @param array $params
    * @return Builder
    */
    public function getRecords(array $params): Builder
    {
        return $this->bills_repository->getBillsRecords([
                'user_id' => App::getUserId(),
                'with'    => $this->getRelationsByColumns($params),
                'is_extended_mode' => !empty($params['is_extended_mode'])
        ]);
    }

    /**
     *
     * @param string $column
     * @param Bill $record
     * @param array $params
     * @return mixed
     */
    public function prepareCell(string $column, Bill $record, array $params)
    {
        $content = $record[$column];

        switch ($column) {
            case 'name' :
                $content = HTML::link(null, null, URL('bills', 'bills', 'item', [
                        'id'       => $record->getKey(),
                        'location' => aget($params, 'location')
                        ]), $content);
                break;

            case 'price' :
                $content = $content ?: 0;
                break;

            case 'status_id' :
                $content = $this->statuses[oget($record, 'status_id')] ?? '';
                break;

            case 'created_by' :
                $content = $record->creator->full_name;
                break;

            case 'updated_by' :
                $content = $content ? $record->updater->full_name : '';
                break;

            case 'manager_id' :
                $content = $content ? oget($record->manager, 'full_name', '') : $content;
                break;

            case 'company_id' :
                $content = $content ? oget($record->company, 'name', '') : $content;
                break;

            case 'created_at' :
                $content = $record->created_at->toDateTimeString();
                break;

            case 'updated_at' :
                $content = $content ? $record->updated_at->toDateTimeString() : null;
                break;

            case 'assignees' :
                $ids = $content->toArray();

                if ($ids) {
                    $assignees = TicketAssignees::assigneeDetails($ids, false);
                    $content = TicketsFormatter::getAssigneeFormat($assignees, '<br><br>');
                } else {
                    $content = null;
                }

                break;
        }

        return $content;
    }

    /**
     *
     * @param Builder $records
     * @param array $filters
     * @return Builder
     */
    public function applyFilter(Builder  $records, array $filters): Builder
    {
        $filters['source'] = $this->preprocessSelection($filters['source']);

        foreach ($filters['source'] as $k => $v) {
            $name = aget($v, 'name');
            $value = aget($v, 'value');

            if (stristr($name, '[assignee_user_id]')) {
                $records->whereHas('assignees', function ($q) use ($value) {
                    $q->where('assignee_user_id', $value);
                });

                unset($filters['source'][$k]);
            }

            if (stristr($name, '[assignee_user_group_id]')) {
                $records->whereHas('assignees', function ($q) use ($value) {
                    $q->where('assignee_user_group_id', $value);
                });

                unset($filters['source'][$k]);
            }

            if (stristr($name, '[assignee_company_id]')) {
                $records->whereHas('assignees', function ($q) use ($value) {
                    $q->where('assignee_company_id', $value);
                });

                unset($filters['source'][$k]);
            }

            if (stristr($name, 'status_id[]')) {
                $records->whereIn('status_id', $value);

                unset($filters['source'][$k]);
            }
        }

        if ($attributes = aget($filters, 'attributes')) {
            $records->attributesFilter($attributes);
        }

        return $this->traitApplyFilter($records, $filters);
    }

    /**
     *
     * @param string $id
     * @param Bill $record
     * @param array $params
     * @return array
     */
    public function renderButtonsRow(string $id, Bill $record, ?array $params = []): array
    {
        $edit_url = URL('bills', 'bills', 'item', [
            'id'       => $record->id,
            'location' => aget($params, 'location')
        ]);

        return [
            HTML::link(uniqid(), 'button-icon command-edit-bill', $edit_url, '<i class="material-icons" data-toggle="tooltip" title="Edit">edit</i>', ['data-id' => $id]),
            HTML::link(uniqid(), 'button-icon command-remove', '#', '<i class="material-icons" data-toggle="tooltip" title="Delete">close</i>', ['data-id' => $id])
        ];
    }

    public function updateBillableItemsList()
    {
        $error = null;
        $data = Inputs::only(['bill_id', 'entity', 'entity_ids', 'billable_item_ids']);

        try {
            $id = Inputs::get('bill_id', -1);
            $entity = Inputs::get('entity');
            $entity_ids = Inputs::get('entity_ids', '');
            $billable_item_ids = Inputs::get('billable_item_ids', '');

            if (!$entity_ids && !$billable_item_ids) {
                throw new Exception('No selected items');
            }

            $bill = $this->bill_repository->getById((int)$id);

            if ($billable_item_ids) {
                $this->billable_item_repository->destroy(explode(',', $billable_item_ids));
            }

            if ($entity_ids) {
                $billable_items_data = [];

                foreach (explode(',', $entity_ids) as $value) {
                    $billable_items_data [] = [
                        'billable_type' => $entity,
                        'billable_id'   => $value,
                    ];
                }

                $this->bill_repository->createManyRelationEntities($bill, 'billable_items', $billable_items_data);
            }

            p_s('Data successfully updated', [
                'billable_items_list' => $this->bills_repository->billableItemsIdsGroupedByType($bill)
            ]);
        } catch (Exception $e) {
            $error = $this->getErrorMessage($e->getCode(), $e->getMessage());
        }

        p_e($error);
    }

    /**
     *
     * @param int|null $bill_id
     * @return json
     */
    public function getPriceById(?int $bill_id)
    {
        try {
            $id = $bill_id ?: Inputs::get('id', -1);

            $bill = $this->bill_repository->getById((int)$id);

            p_s('', ['price' => $bill->price]);
        } catch (Exception $e) {
            $error = $this->getErrorMessage($e->getCode(), $e->getMessage());
        }

        p_e($error);
    }

    /**
     * Save POST data from UI
     * @return json
     */
    public function update(BillSavingService $bill_saver)
    {
        $error = null;

        try {
            $validators = $this->setupValidators($this->rules, Inputs::all());

            if ($messages = aget($validators['messages'], 'editor')) {
                $errors = [];

                foreach ($messages as $k => $v) {
                    $errors [] = implode('<br>', $v);
                }

                p_e('Invalid values', ['message' => implode('<br>', $errors)]);
            }

            if ($id = $bill_saver->save(Inputs::all())) {
                p_s('Data successfully saved', ['message' => $id]);
            }
        } catch (Exception $e) {
            $error = $this->getErrorMessage($e->getCode(), $e->getMessage());
        }

        p_e($error);
    }

    /**
     * Remove dependent items
     */
    public function remove()
    {
        $res = [
            'success' => true,
            'message' => 'Record deleted'
        ];

        try {
            $ids = Inputs::get('ids');
            $this->bill_repository->destroy($ids);
        } catch (Exception $e) {
            $code = $e->getCode();
            $message = $e->getMessage();

            $res['success'] = false;
            $res['exception'] = cut($message, 100);
            $res['message'] = $this->getErrorMessage($code, $message);

            $this->response->setJsonOutput($res);
        }

        $this->response->setJsonOutput($res);

    }

}
