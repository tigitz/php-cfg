<?php

/*
 * This file is part of PHP-CFG, a Control flow graph implementation for PHP
 *
 * @copyright 2015 Anthony Ferrara. All rights reserved
 * @license MIT See LICENSE at the root of the project for more info
 */

namespace PHPCfg\Visitor;

use PHPCfg\Block;
use PHPCfg\Op;
use PHPCfg\Visitor;

class Simplifier implements Visitor {
    protected $removed;
    protected $recursionProtection;
    public function __construct() {
        $this->removed = new \SplObjectStorage;
        $this->recursionProtection = new \SplObjectStorage;
    }
    public function enterOp(Op $op, Block $block) {
        if ($this->recursionProtection->contains($op)) {
            return;
        }
        $this->recursionProtection->attach($op);
        foreach ($op->getSubBlocks() as $name) {
            /** @var Block $block */
            $targets = $op->$name;
            if (!is_array($targets)) {
                $targets = [$targets];
            }
            $results = [];
            foreach ($targets as $key => $target) {
                $results[$key] = $target;
                if (!$target || !isset($target->children[0]) || !$target->children[0] instanceof Op\Stmt\Jump) {
                    continue;
                }
                if ($this->removed->contains($target)) {
                    // short circuit
                    $results[$key] = $target->children[0]->target;
                    if (!in_array($block, $target->children[0]->target->parents, true)) {
                        $target->children[0]->target->parents[] = $block;
                    }
                    continue;
                }

                if (!isset($target->children[0]) || !$target->children[0] instanceof Op\Stmt\Jump) {
                    continue;
                }

                // First, optimize the child:
                $this->enterOp($target->children[0], $target);

                if ($target->children[0]->target === $target) {
                    // Prevent killing infinite tight loops
                    continue;
                }

                if (count($target->phi) > 0) {
                    // It's a phi block, we can't reassign it
                    // Handle the VERY specific case of a double jump with a phi node on both ends'

                    $found = [];
                    foreach ($target->phi as $phi) {
                        $foundPhi = null;
                        foreach ($target->children[0]->target->phi as $subPhi) {
                            if ($subPhi->hasOperand($phi->result)) {
                                $foundPhi = $subPhi;
                                break;
                            }
                        }
                        if (!$foundPhi) {
                            // At least one phi is not directly used
                            continue 2;
                        }
                        $found[] = [$phi, $foundPhi];
                    }
                    // If we get here, we can actually remove the phi node and teh jump
                    foreach ($found as $nodes) {
                        $phi = $nodes[0];
                        $foundPhi = $nodes[1];
                        $foundPhi->removeOperand($phi->result);
                        foreach ($phi->vars as $var) {
                            $foundPhi->addOperand($var);
                        }
                    }
                    $target->phi = [];
                }
                $this->removed->attach($target);
                $target->dead = true;

                // Remove the target from the list of parents
                $k = array_search($target, $target->children[0]->target->parents, true);
                unset($target->children[0]->target->parents[$k]);
                $target->children[0]->target->parents = array_values($target->children[0]->target->parents);

                if (!in_array($block, $target->children[0]->target->parents, true)) {
                    $target->children[0]->target->parents[] = $block;
                }

                $results[$key] = $target->children[0]->target;
            }
            if (!is_array($op->$name)) {
                $op->$name = $results[0];
            } else {
                $op->$name = $results;
            }
        }
        $this->recursionProtection->detach($op);
    }

    public function leaveOp(Op $op, Block $block) {}
    public function enterBlock(Block $block, Block $prior = null) {}
    public function leaveBlock(Block $block, Block $prior = null) {}
    public function skipBlock(Block $block, Block $prior = null) {}
    
}