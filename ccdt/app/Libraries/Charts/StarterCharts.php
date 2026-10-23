<?php

namespace App\Libraries\Charts;

/**
 * Ready-made charts for IQ SCDIF tables.
 *
 * CMS imports name their columns from the record type layout in the
 * `recordtypes` table (App\Helpers\CMSHelper), so the starters are matched by
 * column NAME rather than by record type: a starter is offered for a table only
 * if every role it needs resolves to a column of that table. Each role lists
 * the names used by the two layouts (IQ v1 "correspondence/casework" and v2
 * "communication/workflow"). Tables imported without a header get Field0,
 * Field1... names; they match nothing, and the builder is used by hand.
 */
class StarterCharts
{
    /** role => candidate column names (formatFieldName() form) */
    private static $roles = [
        'comm_type'  => ['communication_type', 'correspondence_type'],
        'date_in'    => ['date_in'],
        'date_out'   => ['date_out'],
        'comm_code'  => ['communication_code', 'correspondence_code'],
        'status'     => ['status', 'casework_status'],
        'start_date' => ['start_date'],
        'wf_type'    => ['workflow_type'],
        'state'      => ['state_code', 'state'],
        'user'       => ['user_id', 'staff'],
        'resp_type'  => ['response_type'],
    ];

    /** The charts. "needs" lists the roles used; {role} placeholders in the spec are replaced by column names. */
    private static function definitions()
    {
        return [
            [
                'id' => 'comms_per_month', 'label' => 'Communications per month, by type',
                'needs' => ['date_in', 'comm_type'],
                'spec' => ['chart' => 'line', 'x' => ['column' => '{date_in}', 'bucket' => 'month'],
                           'group' => ['column' => '{comm_type}'], 'measure' => ['fn' => 'count']],
            ],
            [
                'id' => 'comms_per_year', 'label' => 'Communications per year',
                'needs' => ['date_in'],
                'spec' => ['chart' => 'bar', 'x' => ['column' => '{date_in}', 'bucket' => 'year'], 'measure' => ['fn' => 'count']],
            ],
            [
                'id' => 'comms_by_status', 'label' => 'Communications by status',
                'needs' => ['date_in', 'status'],
                'spec' => ['chart' => 'hbar', 'x' => ['column' => '{status}'], 'measure' => ['fn' => 'count']],
            ],
            [
                'id' => 'days_to_close', 'label' => 'Days from received to closed (histogram)',
                'needs' => ['date_in', 'date_out'],
                'spec' => ['chart' => 'hist', 'x' => ['kind' => 'days_between', 'from' => '{date_in}', 'to' => '{date_out}', 'cap' => 365, 'bin' => 7],
                           'measure' => ['fn' => 'count']],
            ],
            [
                'id' => 'top_topics', 'label' => 'Top 20 topic codes',
                'needs' => ['comm_code'],
                'spec' => ['chart' => 'hbar', 'x' => ['column' => '{comm_code}'], 'measure' => ['fn' => 'count'], 'limit' => 20],
            ],
            [
                'id' => 'workflow_starts', 'label' => 'Workflow starts per month, by status',
                'needs' => ['start_date', 'status', 'wf_type'],
                'spec' => ['chart' => 'stacked', 'x' => ['column' => '{start_date}', 'bucket' => 'month'],
                           'group' => ['column' => '{status}'], 'measure' => ['fn' => 'count']],
            ],
            [
                'id' => 'addresses_by_state', 'label' => 'Addresses by state (top 20)',
                'needs' => ['state'],
                'spec' => ['chart' => 'hbar', 'x' => ['column' => '{state}'], 'measure' => ['fn' => 'count'], 'limit' => 20],
            ],
        ];
    }

    /**
     * @param string[] $columnNames column names of the table
     * @return array list of ['id', 'label', 'spec'] with real column names filled in
     */
    public static function forColumns(array $columnNames)
    {
        $resolved = self::resolve($columnNames);

        $out = [];
        foreach (self::definitions() as $def) {
            $ok = true;
            foreach ($def['needs'] as $role) {
                if (!isset($resolved[$role])) {
                    $ok = false;
                    break;
                }
            }
            if (!$ok) {
                continue;
            }
            $out[] = ['id' => $def['id'], 'label' => $def['label'], 'spec' => self::fill($def['spec'], $resolved)];
        }
        return $out;
    }

    /** role => the table's column name playing that role */
    private static function resolve(array $columnNames)
    {
        $lookup = array_flip($columnNames);
        $resolved = [];
        foreach (self::$roles as $role => $candidates) {
            foreach ($candidates as $c) {
                if (isset($lookup[$c])) {
                    $resolved[$role] = $c;
                    break;
                }
            }
        }
        return $resolved;
    }

    private static function fill($node, array $resolved)
    {
        if (is_array($node)) {
            foreach ($node as $k => $v) {
                $node[$k] = self::fill($v, $resolved);
            }
            return $node;
        }
        if (is_string($node) && preg_match('/^\{(\w+)\}$/', $node, $m)) {
            return $resolved[$m[1]];
        }
        return $node;
    }

    /**
     * Charts that combine two tables on an ID column, in the orientation
     * "main table a, joined table b". Both orientations of the chosen pair are
     * tried, so the result does not depend on which table was picked first.
     *
     * @param string[] $namesA columns of the main table
     * @param string[] $namesB columns of the joined table
     * @return array list of ['id', 'label', 'spec'] (each spec has a "join")
     */
    public static function forJoin(array $namesA, array $namesB)
    {
        $out = [];
        $seen = [];
        foreach ([false, true] as $swap) {
            $a = $swap ? $namesB : $namesA;
            $b = $swap ? $namesA : $namesB;
            $ra = self::resolve($a);
            $rb = self::resolve($b);
            foreach (self::joinDefinitions() as $def) {
                if (isset($seen[$def['id']])) {
                    continue;
                }
                foreach ($def['needs_a'] as $role) {
                    if (!isset($ra[$role])) {
                        continue 2;
                    }
                }
                foreach ($def['needs_b'] as $role) {
                    if (!isset($rb[$role])) {
                        continue 2;
                    }
                }
                $key = null;
                foreach ($def['keys'] as $k) {
                    if (in_array($k, $a, true) && in_array($k, $b, true)) {
                        $key = $k;
                        break;
                    }
                }
                if ($key === null) {
                    continue;
                }
                $spec = self::fillJoin($def['spec'], $ra, $rb, $key);
                $spec['join'] = ['left' => $key, 'right' => $key];
                if ($swap) {
                    $spec = self::swapTables($spec);
                }
                $seen[$def['id']] = true;
                $out[] = ['id' => $def['id'], 'label' => $def['label'], 'spec' => $spec];
            }
        }
        return $out;
    }

    private static function joinDefinitions()
    {
        return [
            [
                'id' => 'comms_by_state', 'label' => 'Communications by state (top 20)',
                'needs_a' => ['date_in'], 'needs_b' => ['state'], 'keys' => ['address_id'],
                'spec' => ['chart' => 'hbar', 'x' => ['column' => '{b.state}', 'src' => 'b'], 'measure' => ['fn' => 'count'], 'limit' => 20],
            ],
            [
                'id' => 'comm_type_by_state', 'label' => 'Communication type by state',
                'needs_a' => ['date_in', 'comm_type'], 'needs_b' => ['state'], 'keys' => ['address_id'],
                'spec' => ['chart' => 'heatmap', 'x' => ['column' => '{b.state}', 'src' => 'b'], 'group' => ['column' => '{a.comm_type}', 'src' => 'a'],
                           'measure' => ['fn' => 'count'], 'limit' => 15],
            ],
            [
                'id' => 'topics_by_month', 'label' => 'Topic codes per month (top 8)',
                'needs_a' => ['comm_code'], 'needs_b' => ['date_in'], 'keys' => ['communication_id', 'correspondence_id'],
                'spec' => ['chart' => 'line', 'x' => ['column' => '{b.date_in}', 'src' => 'b', 'bucket' => 'month'],
                           'group' => ['column' => '{a.comm_code}', 'src' => 'a'], 'measure' => ['fn' => 'count']],
            ],
            [
                'id' => 'comms_by_workflow_type', 'label' => 'Communications by workflow type',
                'needs_a' => ['date_in'], 'needs_b' => ['wf_type'], 'keys' => ['workflow_id'],
                'spec' => ['chart' => 'hbar', 'x' => ['column' => '{b.wf_type}', 'src' => 'b'], 'measure' => ['fn' => 'count'], 'limit' => 20],
            ],
        ];
    }

    /** Fill {a.role}, {b.role} and {key} placeholders. */
    private static function fillJoin($node, array $ra, array $rb, $key)
    {
        if (is_array($node)) {
            foreach ($node as $k => $v) {
                $node[$k] = self::fillJoin($v, $ra, $rb, $key);
            }
            return $node;
        }
        if (is_string($node) && preg_match('/^\{(a|b)\.(\w+)\}$/', $node, $m)) {
            return $m[1] === 'a' ? $ra[$m[2]] : $rb[$m[2]];
        }
        if ($node === '{key}') {
            return $key;
        }
        return $node;
    }

    /** Exchange main and joined table in a finished spec. */
    private static function swapTables($node)
    {
        if (!is_array($node)) {
            return $node;
        }
        foreach ($node as $k => $v) {
            if (in_array($k, ['src', 'from_src', 'to_src'], true) && ($v === 'a' || $v === 'b')) {
                $node[$k] = $v === 'a' ? 'b' : 'a';
            } elseif ($k === 'join' && is_array($v)) {
                $node[$k] = ['left' => $v['right'], 'right' => $v['left']];
            } else {
                $node[$k] = self::swapTables($v);
            }
        }
        return $node;
    }
}
