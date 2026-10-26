<?php

declare(strict_types=1);

namespace Workspace\Agents\Kernel;

/**
 * Task execution status. Same vocabulary as the work-unit delivery workflow
 * (saas-platform docs/quality/WORK_UNIT_DELIVERY_WORKFLOW.md "Status Vocabulary").
 */
enum Status: string
{
    /** The agent ran to completion. Findings may still exist; read them. */
    case Pass = 'PASS';
    /** The agent itself errored; its output must not be trusted. */
    case Failed = 'FAILED';
    /** A named precondition was not met; nothing was evaluated. */
    case Blocked = 'BLOCKED';
}
