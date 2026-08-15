<?php

declare(strict_types=1);

namespace App\Garmin;

/**
 * Which tables a SELECT actually reads, asked of the planner rather than guessed.
 *
 * Permission checks used to match table names against the SQL text, which is
 * wrong in both directions: "select 1 as body_comp from days" was refused
 * although it reads nothing protected, while any wrapping that hides the
 * literal name slips through. EXPLAIN plans the statement without running it
 * and names every relation the plan opens, so aliases, CTEs, subqueries,
 * set operations and joins all resolve to their real tables.
 *
 * Indexes need no special handling here the way they did on SQLite: an
 * index-only scan still reports the relation it belongs to, and the index
 * name is a separate field this never looks at.
 */
class QueryTables
{
    /**
     * @return list<string> table names, lower-cased, in no particular order
     */
    public function for(string $sql): array
    {
        $connection = Mirror::connection();

        // FORMAT JSON because the text form would have to be parsed back out
        // of a drawing; costs off because none of the numbers matter here.
        // The single column comes back captioned "QUERY PLAN", with a space,
        // so it is read positionally rather than by name.
        $row = (array) $connection->selectOne('explain (format json, costs off) '.$sql);

        $tables = [];
        $this->collect(json_decode((string) reset($row), true, flags: JSON_THROW_ON_ERROR), $tables);

        return array_keys($tables);
    }

    /**
     * Walk every nested array rather than only the "Plans" key: init plans,
     * sub plans and materialised CTEs all hang off the tree under their own
     * captions, and a node that names a relation names it the same way
     * wherever it sits.
     *
     * @param  array<array-key, mixed>  $node
     * @param  array<string, true>  $tables
     */
    private function collect(array $node, array &$tables): void
    {
        if (isset($node['Relation Name'])) {
            $tables[strtolower((string) $node['Relation Name'])] = true;
        }

        foreach ($node as $value) {
            if (is_array($value)) {
                $this->collect($value, $tables);
            }
        }
    }
}
