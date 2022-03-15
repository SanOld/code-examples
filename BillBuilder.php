<?php

declare(strict_types=1);

namespace eloquent\Bill;

use Illuminate\Database\Eloquent\Builder;

final class BillBuilder extends Builder
{

    /**
     *
     * @param string|array|null $user_ids
     * @return \self
     */
    public function managerIds($user_ids = []): self
    {
        $user_ids = (array) $user_ids;
        return $this->whereIn('manager_id', $user_ids ?: [-1]);
    }

    /**
     *
     * @param string|array|null $user_ids
     * @param bool|null $is_extended_mode
     * @return self
     */
    public function assigneeIds($user_ids = [], ?bool $is_extended_mode = false): self
    {
        $user_ids = (array) $user_ids;

        $this->whereHas('assignees', /** @var TicketAssignees $query **/function($query) use ($user_ids, $is_extended_mode) {
            $query->where(/** @var TicketAssignees $query **/function($query) use ($user_ids, $is_extended_mode) {
                $query->orWhere(/** @var TicketAssignees $query **/function ($query) use ($user_ids) {
                    $query->assignedTo($user_ids, null, null, \TicketAssignees::RULE_ANY);
                });

                if (!empty($is_extended_mode)) {
                    $combinations = [];
                    $relations = \UserGroupCompanyRelation::whereIn('user_id', $user_ids
                                ?: [-1])->get();

                    // Sometimes user may have such relations of company:group:user like 1:1:1, 1:2:1, 1:2:2, which means company = 1, group = 1, user = X
                    // We must filter SQL results not only by user, but by his group and company
                    // Resulting SQL will contain such conditions :
                    // company = 1 OR (company = 1 AND group = 1) OR (company = 1 AND group = 1 AND user = 1)
                    // company = 1 OR (company = 1 AND group = 2) OR (company = 1 AND group = 2 AND user = 1)
                    // company = 1 OR (company = 1 AND group = 2) OR (company = 1 AND group = 2 AND user = 2)
                    // First two conditions may be groupped, so we don't have to complicate query repeating e.g. this part (company = 1)
                    // company = 1
                    // OR (company = 1 AND group = 1)
                    // OR (company = 1 AND group = 2)
                    // OR (company = 1 AND group = 1 AND user = 1)
                    // OR (company = 1 AND group = 2 AND user = 2)

                    foreach ($relations as $relation) {
                        $group_id = $relation->user_group_id;
                        $company_id = $relation->company_id;
                        $combinations['C' . $company_id] = $relation;
                        $combinations['C' . $company_id . 'G' . $group_id] = $relation;
                    }

                    foreach ($combinations as $combo) {
                        $query->orWhere(function ($query) use ($combo) {
                            $group_id = $combo->user_group_id;
                            $company_id = $combo->company_id;

                            $query->orWhere(function ($query) use ($group_id, $company_id) {
                                $query->assignedTo(null, $group_id, $company_id, \TicketAssignees::RULE_NORMAL);
                            });
                        });
                    }
                }
            });
        });

        return $this;
    }

}