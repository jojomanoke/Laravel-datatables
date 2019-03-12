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
     * The table ID
     *
     * @var mixed
     * @author Wim Pruiksma
     */
    protected $tableid = false;

    /**
     * Columns with filters and their value
     *
     * @var array
     * @author Sylvano Verkuyl
     */
    protected $columnSearch;

    /**
     * If DataTables has searchable keys
     *
     * @var boolean
     * @author Wim Pruiksma
     */
    protected $hasSearchable = false;

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
        $this->model = $model;
        $this->table = $this->model->getTable();
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
        $allowedID = $collection->pluck('id');
        $first = $collection->first();
        $empty = $first ? new $first : null;
        $this->build();
        $this->model = $first ? $first::query()->whereIn('id', $allowedID) : null;
        $this->table = $first ? $empty->getTable() : null;
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
        $this->draw = Request::get('draw');
        $this->column = Request::get('columns');
        $this->order = [
            'column' => $this->column[Request::get('order')[0]['column']]['data'],
            'dir' => Request::get('order')[0]['dir']
        ];
        $this->start = Request::get('start');
        $this->length = Request::get('length');
        $this->search = (Request::has('search') && Request::get('search')['value']) ? Request::get('search') : null;
        if (Request::has('columns')) {
            foreach (Request::get('columns') as $col) {
                if (isset($col['search']['value'])) {
                    $this->columnSearch[$col['data']] = $col['search']['value'];
                }
            }
        }
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
     * @return $this
     * @deprecated since version 2.0.17
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
        $last = [];
        foreach ($searchkeys as $key => $value) {
            if (str_contains($value, '.')) {
                $last[] = $value;
            } else {
                $this->searchable[] = $value;
            }
        }
        $this->searchable = array_merge($this->searchable, $last);
        $this->hasSearchable = true;
        return $this;
    }

    /**
     * Run the query
     * return as json string
     * @author Wim Pruiksma
     */
    public function get()
    {
        if (!Request::has('draw')
            || ($this->tableid !== false
                && Request::has("table")
                && Request::get('table') !== $this->tableid)) {
            return false;
        }

        $data = $this->execute();
        $data['draw'] = $this->draw;

        $response = response()->json($data);

        foreach ($response->headers->all() as $header => $value) {
            $set = implode($value, ',');
            header("$header: $set");
        }
        echo $response->getContent();
        exit;
    }

    /**
     * execute the queries
     *
     * @return array
     */
    protected function execute()
    {
        if ($this->model && $this->search && $this->hasSearchable) {
            $this->searchOnModel();
        }

        if ($this->columnSearch) {
            $this->searchForValues();
        }

        $count = $this->model ? $this->model->count() : 0;
        $model = $this->model ? $this->sortModel() : null;
        $build = collect([]);

        $model->each(function ($item, $key) use ($build) {
            $build->put($key + $this->start, $item);
        });
        if ($model) {
            $collection = $this->encryptKeys($build->unique()->values()->toArray());
        }

        $data['recordsTotal'] = $count;
        $data['recordsFiltered'] = $count;
        $data['data'] = $collection ?? [];

        return $data;
    }

    /**
     * Order the model
     *
     * @return \Illuminate\Database\Eloquent\Collection
     */
    private function sortModel()
    {

        $build = $this->hasSearchable ? $this->model->skip($this->start)->take($this->length) : $this->model;

        $sortByRelation = str_contains($this->order['column'], '.');

        if ($sortByRelation) {
            $model = $this->order['dir'] === 'asc' ? $build->get()->sortBy($this->order['column']) : $build->get()->sortByDesc($this->order['column']);
        } else {
            $model = $build->orderBy($this->order['column'], $this->order['dir'])->get();
        }

        if ($this->search && !$this->hasSearchable) {
            $model = $this->searchOnCollection($model);
        }

        if (!$this->hasSearchable) {
            return $model->slice($this->start, $this->length);
        }

        return $model;
    }

    /**
     * Search on the model
     *
     * @return void
     * @author Wim Pruiksma
     */
    private function searchOnModel()
    {
        foreach ($this->searchable as $index => $column) {
            if (str_contains($column, '.')) {
                $this->setSearchOnRelation($column);
                continue;
            }

            if ($index === 0) {
                $this->model = $this->model->whereRaw("lower(`$column`) LIKE ?", "%{$this->search['value']}%");
            } else {
                $this->model = $this->model->orWhereRaw("lower(`$column`) LIKE ?", "%{$this->search['value']}%");
            }

        }
    }

    /**
     * Search by given values
     *
     * @author Sylvano Verkuyl <sylvano.verkuyl@nugtr.nl>
     */
    private function searchForValues()
    {
        $index = 0;
        foreach ($this->columnSearch as $column => $value) {
            if (!strpos($value, ',') && !strpos($column, '_id')) {
                if ($index == 0) {
                    $this->model = $this->model->whereRaw("$column = $value");
                    $index++;
                } else {
                    $this->model = $this->model->orWhereRaw("$column = $value");
                }
            } elseif (strpos($column, '_id')) {
                $this->searchForeignKeys($column, $value);
            } else {
                $this->model = $this->model->whereBetween($column, explode(',', $value));
            }
        }
    }

    /**
     * If column name has _id, this will search for related items
     *
     * @param string $column
     * @param string $value
     * @author Sylvano Verkuyl <sylvano.verkuyl@nugtr.nl>
     */
    private function searchForeignKeys(string $column, string $value)
    {
        $index = 0;
        foreach (explode(',', $value) as $val) {
            if ($index == 0) {
                $this->model = $this->model->whereRaw("$column = $val");
                $index++;
            } else {
                $this->model = $this->model->orWhereRaw("$column = $val");
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

        $this->model = $this->model->orWhereHas($explode[0], function ($query) use ($explode) {
            $query->whereRaw("lower(`$explode[1]`) LIKE ?", "%{$this->search['value']}%");
        });

    }

    /**
     * Create a macro search on the collection
     *
     * @param mixed $collection
     * @return collection
     */
    private function searchOnCollection($collection)
    {
        $this->createSearchMacro();
        $this->createSearchableKeys();
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
                        $type = $collect instanceof \Illuminate\Database\Eloquent\Collection ? '.*.' : '.';
                        $this->searchable[] = $name . $type . $col;
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
        foreach ($data as $key => $value) {
            if (is_array($value)) {
                $data[$key] = $this->encryptKeys($value);
            } else {
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
        if (!is_array($this->encrypt)) {
            return $value;
        }
        if (in_array($key, $this->encrypt)) {
            return encrypt($value);
        } else {
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
        $this->tableid = $table;
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
