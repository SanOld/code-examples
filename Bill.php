<?php

use eloquent\Bill\BillBuilder;

/**
 * Class Bill
 *
 */
class Bill extends BaseModel
{

    use EavModelTrait;
    use EntityRelationshipsTrait;

    protected $table = 'bills';
    protected $primaryKey = 'id';
    protected $appends = ['full_name', 'price'];
    protected $fillable = [
        'id',
        'name',
        'start_date',
        'end_date',
        'manager_id',
        'company_id',
        'status_id',
        'created_by',
        'created_at',
        'updated_by',
        'updated_at',
    ];

    public $timestamps = false;

    protected static function boot()
    {
        parent::boot();

        static::creating(/** @var Bill $obj **/function ($obj) {
            $obj->created_by = App::getUserId();
        });

        static::updating(/** @var Bill $obj **/function ($obj) {
            $obj->updated_by = App::getUserId();
        });
    }

    public static function query(): BillBuilder
    {
        return (new static)->newQuery();
    }

    public function newEloquentBuilder($query): BillBuilder
    {
        return new BillBuilder($query);
    }


    // Attributes
    public function getPriceAttribute()
    {
        $billable_items = $this->billable_items();
        if ($billable_items) {
            return $billable_items->sum('price');
        }

        return 0;
    }

    public function getFullNameAttribute()
    {
        $assignee = null;

        if ($assignee = $this->assignees()->with(['user', 'group', 'company'])->first()) {

            $assignee = ' for ' .
                oget($assignee->company, 'name', '[None]') . ' : ' .
                oget($assignee->group, 'name', '[None]') . ' : ' .
                oget($assignee->user, 'full_name', '[None]');
        }

        $end_date = oget($this, 'end_date', '[None]');
        $start_date = oget($this, 'start_date', '[None]');

        $dates = ' from ' . $start_date . ' to ' . $end_date;
        
        return '#' . oget($this, 'id', '[None]') . ' - ' . oget($this, 'name', '[None]') . ' - ' . $dates . $assignee;
    }

    // Relations
    public function billable_items()
    {
        return $this->hasMany(BillableItem::class);
    }

    public function createdBy()
    {
        return $this->belongsTo(UserModel::class, 'created_by', 'user_id');
    }

    public function updatedBy()
    {
        return $this->belongsTo(UserModel::class, 'updated_by', 'user_id');
    }

    public function user()
    {
        return $this->belongsTo(UserModel::class, 'assignee_user_id', 'user_id');
    }

    public function manager()
    {
        return $this->hasOne(UserModel::class, 'user_id', 'manager_id');
    }

    public function company()
    {
        return $this->hasOne(Company::class, 'id', 'company_id');
    }

    public function assignees()
    {
        return $this->hasMany(TicketAssignees::class, 'bill_id');
    }

    public function status()
    {
        return $this->belongsTo(Status::class, 'status_id', 'status_id');
    }

}
