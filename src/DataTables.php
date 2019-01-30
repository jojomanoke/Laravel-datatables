<?php

namespace ACFBentveld\DataTables;

use ACFBentveld\DataTables\DataTablesException;
use Request;
use Schema;
use ACFBentveld\DataTables\DataTablesQueryBuilders;

/**
 * An laravel jquery datatables package
 *
 * @author Wim Pruiksma
 */
class DataTables extends DataTablesQueryBuilders
{
    /**
     * The collectiosn model
     *
     * @var mixed
     * @author Wim Pruiksma
     */
    protected $model;

    /**
     * Set to true to enable caching
     *
     * @var boolean
     */
    protected $remember = false;

    /**
     * Set the keys for encrypting
     *
     * @var array
     * @author Wim Pruiksma
     */
    protected $encrypt;

    /**
     * Set the search keys
     *
     * @var array
     * @author Wim Pruiksma
     */
    protected $search;

    /**
     * The database columns
     *
     * @var mixed
     * @author Wim Pruiksma
     */
    protected $columns;

    /**
     * The database table name
     *
     * @var string
     * @author Wim Pruiksma
     */
    protected $table;

    /**
     * Searchable keys
     *
     * @var array
     * @author Wim Pruiksma
     */
    protected $searchable;
    
    /**
     * The table
     *
     * @var mixed
     * @author Wim Pruiksma
     */
    protected $table = false;

    /**
     * Set the class and create a new model instance
     *
     * @param \Illuminate\Database\Eloquent\Model $model
     * @return $this
     * @throws DataTablesException
     * @author Wim Pruiksma
     */
    public function model($model)
    {
        $this->instanceCheck($model);
        $this->build();
        $this->model   = $model;
        $this->table   = $this->model->getTable();
        $this->columns = Schema::getColumnListing($this->table);
        return $this;
    }

    /**
     * The collect method
     * Really bad for performance
     *
     * @param \Illuminate\Database\Eloquent\Collection $collection
     * @return $this
     * @throws DataTablesException
     * @author Wim Pruiksma
     */
    public function collect($collection)
    {
        $this->instanceCheck($collection);
        $allowedID     = $collection->pluck('id');
        $first         = $collection->first();
        $empty         = new $first;
        $this->build();
        $this->model   = $first::query()->whereIn('id', $allowedID);
        $this->table   = $empty->getTable();
        $this->columns = Schema::getColumnListing($this->table);
        return $this;
    }

    /**
     * Build the collection for the datatable
     *
     * @return $this
     * @author Wim Pruiksma
     */
    public function build()
    {
        if (Request::has('response')) {
            $this->response = Request::get('response');
        } elseif (Request::has('draw')) {
            $this->response = 'json';
        }
        $this->draw   = Request::get('draw');
        $this->column = Request::get('columns');
        $this->order  = [
            'column' => $this->column[Request::get('order')[0]['column']]['data'],
            'dir' => Request::get('order')[0]['dir']
        ];
        $this->start  = Request::get('start');
        $this->length = Request::get('length');
        $this->search = (Request::has('search') && Request::get('search')['value'])
                ? Request::get('search') : null;
        return $this;
    }

    /**
     * Check the instance of the given model or collection
     *
     * @param type $instance
     * @return boolean
     * @throws DataTablesException
     * @author Wim Pruiksma
     */
    protected function instanceCheck($instance)
    {
        if (
            !$instance instanceof \Illuminate\Database\Eloquent\Model &&
            !$instance instanceof \Illuminate\Database\Eloquent\Collection &&
            !$instance instanceof \Illuminate\Database\Eloquent\Relations\BelongsToMany &&
            !$instance instanceof \Illuminate\Database\Eloquent\Relations\BelongsTo &&
            !$instance instanceof \Illuminate\Database\Eloquent\Relations\HasMany &&
            !$instance instanceof \Illuminate\Database\Eloquent\Relations\HasOne
        ) {
            throw new DataTablesException('Model must be an instance of Illuminate\Database\Eloquent\Model or an instance of Illuminate\Database\Eloquent\Collection');
        }
        return true;
    }

    /**
     * Enable caching
     * Check if the cache exists.
     * If the cache exists, stop executing and return the json
     *
     * @return $thi
     */
    public function remember(string $name, int $minutes = 60)
    {
        $this->remember = true;
        $this->cacheName = "$name";
        $this->cacheFor = $minutes;
        return $this;
    }

    /**
     * Set the searchkeys
     *
     * @param mixed $searchkeys
     * @return $this
     */
    public function searchable(... $searchkeys)
    {
        $this->searchable = $searchkeys;
        return $this;
    }

    /**
     * Run the query
     * return as json string
     * @author Wim Pruiksma
     */
    public function get()
    {
        if(!Request::has('draw') || ($this->table !== false && !Request::has($this->table)) ){
            return false;
        }
        $data = $this->execute();
        $data['draw'] = $this->draw;
        echo json_encode($data);
        exit;
    }

    /**
     * execute the queries
     *
     * @return array
     */
    protected function execute()
    {
        $count = $this->model->count();
        if ($this->search && $this->searchable) {
            $this->searchOnModel();
        }
        $model = $this->sortModel();
        if($this->search && !$this->searchable){
            $model = $this->createMacro($model);
        }
        $filtered      = $model->count();
        $build = $model->slice($this->start, $this->length);
        $collection              = $this->encryptKeys($build->unique()->values()->toArray());
        $data['recordsTotal']    = $count;
        $data['recordsFiltered'] = $filtered;
        $data['data']            = $collection;
        return $data;
    }

    /**
     * Order the model
     *
     * @return \Illuminate\Database\Eloquent\Collection
     */
    private function sortModel()
    {
        $model = false;
        if($this->remember && !$this->search){
            ini_set('memory_limit', '1G');
            $model = \Cache::remember($this->cacheName, $this->cacheFor, function () {
                return $this->model->get();
            });
        }else{
            $model = $this->model->get();
        }
        return ($this->order['dir'] === 'asc') ? $model->sortBy($this->order['column']) : $model->sortByDesc($this->order['column']);
    }

    /**
     * Search on the model
     *
     * @param \Illuminate\Database\Eloquent\Collection $collection
     * @return \Illuminate\Database\Eloquent\Collection
     * @author Wim Pruiksma
     */
    private function searchOnModel()
    {
        foreach($this->searchable as $index => $column){
            if(str_contains($column, '.')){
                $this->setSearchOnRelation($column);
                continue;
            }
            if($index === 0){
                $this->model->where($column, 'LIKE', "%{$this->search['value']}%");
            }else{
                $this->model->orWhere($column, 'LIKE', "%{$this->search['value']}%");
            }
        }
    }

    /**
     * Set relation on search key
     *
     * @param string $column
     */
    private function setSearchOnRelation(string $column)
    {
        $explode = explode('.', $column);
        $this->model->orWhereHas($explode[0], function($query) use($explode){
            $query->where($explode[1], 'LIKE', "%{$this->search['value']}%");
        });
    }

    /**
     * Create a macro search on the collection
     *
     * @param mixed $collection
     * @return collection
     */
    private function createMacro($collection)
    {
        $this->createSearchMacro();
        if (!$this->searchable) {
            $this->createSearchableKeys();
        }
        $search = $this->search['value'];
        $result = [];
        foreach ($this->searchable as $searchKey) {
            $result[] = $collection->like($searchKey, strtolower($search));
        }
        return collect($result)->flatten();
    }

    /**
     * Create searchable keys
     * If none given it creates its own
     *
     * @author Wim Pruiksma
     */
    private function createSearchableKeys()
    {
        $builder = $this->model;
        foreach ($this->column as $column) {
            $name = str_before($column['data'], '.');
            if ($column['searchable'] != true) {
                continue;
            }
            if (in_array($name, $this->columns)) {
                $this->searchable[] = $name;
                continue;
            }
            if ($name !== 'function' && $builder->has($name) && $builder->first()) {
                if (optional($builder->first()->$name)->first()) {
                    $collect = $builder->first()->$name;
                    foreach ($collect->first()->toArray() as $col => $value) {
                        $type               = $collect instanceof \Illuminate\Database\Eloquent\Collection
                                ? '.*.' : '.';
                        $this->searchable[] = $name.$type.$col;
                    }
                }
            }
        }
    }

    /**
     * Create a macro for the collection
     * It searches inside the collections
     *
     * @author Wim Pruiksma
     */
    private function createSearchMacro()
    {
        \Illuminate\Database\Eloquent\Collection::macro('like',
            function ($key, $search) {
                return $this->filter(function ($item) use ($key, $search) {
                    $collection = data_get($item, $key, '');
                    if (is_array($collection)) {
                        foreach ($collection as $collect) {
                            $contains = str_contains(strtolower($collect),
                                    $search) || str_contains(strtolower($collect),
                                    $search) || strtolower($collect) == $search;
                            if ($contains) {
                                return true;
                            }
                        }
                    } else {
                        return str_contains(strtolower(data_get($item, $key, '')),
                            $search);
                    }
                });
            });
    }

    /**
     * Encrypt the given keys
     *
     * @param array $data
     * @return array
     * @author Wim Pruiksma
     */
    protected function encryptKeys($data)
    {
        foreach($data as $key => $value){
            if(is_array($value)){
                $data[$key] = $this->encryptKeys($value);
            }else{
                $data[$key] = $this->encryptValues($key, $value);
            }
        }
        return $data;
    }

    /**
     * Encrypt the value keys
     *
     * @param mixed $value
     * @return mixed
     */
    private function encryptValues($key, $value)
    {
        if(!is_array($this->encrypt)){
            return $value;
        }
        if(in_array($key, $this->encrypt)){
            return encrypt($value);
        }else{
            return $value;
        }
    }

    /**
     * Set the keys to encrypt
     *
     * @param mixed $encrypt
     * @return $this
     * @author Wim Pruiksma
     */
    public function encrypt(...$encrypt)
    {
        $this->encrypt = (isset($encrypt[0]) && is_array($encrypt[0])) ? $encrypt[0]
                : $encrypt;
        return $this;
    }
    
    /**
     * Set the table
     *
     * @param string $table
     * @return $this
     * @author Wim Pruiksma
     */
    public function table(string $table)
    {
        $this->table = $table;
        return $this;
    }

    /**
     * Use the function to exclude certain column
     *
     * @param mixed $noselect
     * @return $this
     * @deprecated in version ^2.0.0
     * @author Wim Pruiksma
     */
    public function noSelect($noselect)
    {
        return $this->exclude($noselect);
    }

    /**
     * Keys are always returned so this method is depricated
     *
     * @return $this
     * @deprecated in version ^2.0.0
     * @author Wim Pruiksma
     */
    public function withKeys()
    {
        return $this;
    }
}
