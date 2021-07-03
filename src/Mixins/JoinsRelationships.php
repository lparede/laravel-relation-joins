<?php

namespace Reedware\LaravelRelationJoins\Mixins;

use Closure;
use RuntimeException;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Query\JoinClause;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Database\Eloquent\Relations\Relation;

class JoinsRelationships
{
    /**
     * Defines the mixin for {@see $query->joinRelation()}.
     *
     * @return \Closure
     */
    public function joinRelation()
    {
        /**
         * Add a relationship join condition to the query.
         *
         * @param  string                                 $relation
         * @param  \Closure|null                          $callback
         * @param  string                                 $type
         * @param  bool                                   $through
         * @param  \Illuminate\Database\Eloquent\Builder  $relatedQuery
         *
         * @return \Illuminate\Database\Eloquent\Builder|static
         */
        return function ($relation, Closure $callback = null, $type = 'inner', $through = false, Builder $relatedQuery = null) {

            if (strpos($relation, '.') !== false) {
                return $this->joinNestedRelation($relation, $callback, $type, $through);
            }

            if (stripos($relation, ' as ') !== false) {
                [$relation, $alias] = preg_split('/\s+as\s+/i', $relation);
            }

            $relation = ($relatedQuery ?: $this)->getRelationWithoutConstraints($relation);

            if ($relation instanceof MorphTo) {
                throw new RuntimeException('joinRelation() does not support MorphTo relationships.');
            }

            $joinQuery = $relation->getRelationJoinQuery(
                $relation->getRelated()->newQuery(), $relatedQuery ?: $this, $type, $alias ?? null
            );

            // If we're simply passing through a relation, then we want to advance the relation
            // without actually applying any joins. Presumably the developer has already used
            // a modified version of this join, and they don't want to do it all over again.
            if ($through) {
                return $joinQuery;
            }

            // Next we will call any given callback as an "anonymous" scope so they can get the
            // proper logical grouping of the where clauses if needed by this Eloquent query
            // builder. Then, we will be ready to finalize and return this query instance.
            if ($callback) {
                $joinQuery->callScope($callback);
            }

            $this->addJoinRelationWhere(
                $joinQuery, $relation, $type
            );

            return ! is_null($relatedQuery) ? $joinQuery : $this;

        };
    }

    /**
     * Defines the mixin for {@see $query->joinNestedRelation()}.
     *
     * @return \Closure
     */
    protected function joinNestedRelation()
    {
        /**
         * Add nested relationship join conditions to the query.
         *
         * @param  string         $relations
         * @param  \Closure|null  $callback
         * @param  string         $type
         * @param  bool           $through
         *
         * @return \Illuminate\Database\Eloquent\Builder|static
         */
        return function ($relations, Closure $callback = null, $type = 'inner', $through = false) {

            $relations = explode('.', $relations);

            $relatedQuery = $this;

            while (count($relations) > 0) {
                $closure = count($relations) > 1 ? null : $callback;
                $useThrough = count($relations) > 1 && $through;

                $relatedQuery = $this->joinRelation(array_shift($relations), $closure, $type, $useThrough, $relatedQuery);
            }

            return $this;

        };
    }

    /**
     * Defines the mixin for {@see $query->addJoinRelationWhere()}.
     *
     * @return \Closure
     */
    protected function addJoinRelationWhere()
    {
        /**
         * Add the "join relation" condition where clause to the query.
         *
         * @param  \Illuminate\Database\Eloquent\Builder             $joinQuery
         * @param  \Illuminate\Database\Eloquent\Relations\Relation  $relation
         * @param  string                                            $type
         *
         * @return \Illuminate\Database\Eloquent\Builder|static
         */
        return function (Builder $joinQuery, Relation $relation, $type) {

            $joinQuery->mergeConstraintsFrom($relation->getQuery());

            $baseJoinQuery = $joinQuery->toBase();

            if (! empty($baseJoinQuery->joins)) {
                $this->mergeJoins($baseJoinQuery->joins, $baseJoinQuery->bindings['join']);
            }

            $this->join($baseJoinQuery->from, function ($join) use ($baseJoinQuery) {
                if (empty($baseJoinQuery->wheres)) {
                    return;
                }

                // There's an issue with mixing query builder where clauses
                // with join builder where clauses. To solve for this, we
                // have to recursively replace the nested where queries.

                $this->replaceWhereNestedQueryBuildersWithJoinBuilders($baseJoinQuery);

                $join->mergeWheres($baseJoinQuery->wheres, $baseJoinQuery->bindings['where']);
            }, null, null, $type);

            return $this;

        };
    }

    /**
     * Defines the mixin for {@see $query->replaceWhereNestedQueryBuildersWithJoinBuilders()}.
     *
     * @return \Closure
     */
    protected function replaceWhereNestedQueryBuildersWithJoinBuilders()
    {
        /**
         * Replaces the query builders in nested "where" clauses with join builders.
         *
         * @param  \Illuminate\Database\Query\Builder  $query
         *
         * @return void
         */
        return function ($query) {

            $wheres = $query->wheres;

            $wheres = array_map(function ($where) {
                if (!isset($where['query'])) {
                    return $where;
                }

                if ($where['type'] == 'Exists' || $where['type'] == 'NotExists') {
                    return $where;
                }

                $this->replaceWhereNestedQueryBuildersWithJoinBuilders($where['query']);

                $joinClause = new JoinClause($where['query'], 'inner', $where['query']->from);

                foreach (array_keys(get_object_vars($where['query'])) as $key) {
                    $joinClause->{$key} = $where['query']->{$key};
                }

                $where['query'] = $joinClause;

                return $where;
            }, $wheres);

            $query->wheres = $wheres;

        };
    }

    /**
     * Defines the mixin for {@see $query->leftJoinRelation()}.
     *
     * @return \Closure
     */
    public function leftJoinRelation()
    {
        /**
         * Add a relationship left join condition to the query.
         *
         * @param  string         $relation
         * @param  \Closure|null  $callback
         * @param  bool           $through
         *
         * @return \Illuminate\Database\Eloquent\Builder|static
         */
        return function ($relation, Closure $callback = null, $through = false) {
            return $this->joinRelation($relation, $callback, 'left', $through);
        };
    }

    /**
     * Defines the mixin for {@see $query->rightJoinRelation()}.
     *
     * @return \Closure
     */
    public function rightJoinRelation()
    {
        /**
         * Add a relationship right join condition to the query.
         *
         * @param  string         $relation
         * @param  \Closure|null  $callback
         * @param  bool           $through
         *
         * @return \Illuminate\Database\Eloquent\Builder|static
         */
        return function ($relation, Closure $callback = null, $through = false) {
            return $this->joinRelation($relation, $callback, 'right', $through);
        };
    }

    /**
     * Defines the mixin for {@see $query->crossJoinRelation()}.
     *
     * @return \Closure
     */
    public function crossJoinRelation()
    {
        /**
         * Add a relationship cross join condition to the query.
         *
         * @param  string         $relation
         * @param  \Closure|null  $callback
         * @param  bool           $through
         *
         * @return \Illuminate\Database\Eloquent\Builder|static
         */
        return function ($relation, Closure $callback = null, $through = false) {
            return $this->joinRelation($relation, $callback, 'cross', $through);
        };
    }

    /**
     * Defines the mixin for {@see $query->joinThroughRelation()}.
     *
     * @return \Closure
     */
    public function joinThroughRelation()
    {
        /**
         * Add a relationship join condition through a related model to the query.
         *
         * @param  string         $relation
         * @param  \Closure|null  $callback
         * @param  string         $type
         *
         * @return \Illuminate\Database\Eloquent\Builder|static
         */
        return function ($relation, Closure $callback = null, $type = 'inner') {
            return $this->joinRelation($relation, $callback, $type, true);
        };
    }

    /**
     * Defines the mixin for {@see $query->leftJoinThroughRelation()}.
     *
     * @return \Closure
     */
    public function leftJoinThroughRelation()
    {
        /**
         * Add a relationship left join condition through a related model to the query.
         *
         * @param  string         $relation
         * @param  \Closure|null  $callback
         *
         * @return \Illuminate\Database\Eloquent\Builder|static
         */
        return function ($relation, Closure $callback = null) {
            return $this->joinRelation($relation, $callback, 'left', true);
        };
    }

    /**
     * Defines the mixin for {@see $query->rightJoinThroughRelation()}.
     *
     * @return \Closure
     */
    public function rightJoinThroughRelation()
    {
        /**
         * Add a relationship right join condition through a related model to the query.
         *
         * @param  string         $relation
         * @param  \Closure|null  $callback
         *
         * @return \Illuminate\Database\Eloquent\Builder|static
         */
        return function ($relation, Closure $callback = null) {
            return $this->joinRelation($relation, $callback, 'right', true);
        };
    }

    /**
     * Defines the mixin for {@see $query->crossJoinThroughRelation()}.
     *
     * @return \Closure
     */
    public function crossJoinThroughRelation()
    {
        /**
         * Add a relationship cross join condition through a related model to the query.
         *
         * @param  string         $relation
         * @param  \Closure|null  $callback
         *
         * @return \Illuminate\Database\Eloquent\Builder|static
         */
        return function ($relation, Closure $callback = null) {
            return $this->joinRelation($relation, $callback, 'cross', true);
        };
    }
}