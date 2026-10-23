<?php

namespace App\Libraries\Charts;

/**
 * Thrown when a chart spec is invalid or asks for something the chart
 * builder does not allow (unknown column, blocked column, bad filter...).
 * The message is safe to show to the (admin) user.
 */
class ChartSpecException extends \InvalidArgumentException
{
}
