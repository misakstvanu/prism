<?php

declare(strict_types=1);

namespace Misakstvanu\Prism\Contracts;

use Misakstvanu\Prism\Jobs\SendBatchJob;
use Misakstvanu\Prism\Support\Recursion;

/**
 * Marks a class as part of Prism's own machinery so the recursion guard (US-039) can exclude it
 * from capture.
 *
 * The queued flush job {@see SendBatchJob} carries it so the job capture listener (US-064) never
 * traces Prism shipping its own batch — a job that feeds ingest with the act of reaching ingest
 * would loop forever. No methods: it exists only to be recognised by
 * {@see Recursion::isInternalJob()}.
 */
interface PrismInternal {}
