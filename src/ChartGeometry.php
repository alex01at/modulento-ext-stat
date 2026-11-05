<?php

declare(strict_types=1);

namespace Modulento\Stat;

/** Turns StatQuery::viewsOverTime()'s rows into SVG coordinates - kept out of the template, which only places them. */
final class ChartGeometry
{
    private const WIDTH = 720;
    private const HEIGHT = 220;
    private const PAD_LEFT = 42;
    private const PAD_RIGHT = 10;
    private const PAD_TOP = 10;
    private const PAD_BOTTOM = 26;

    /**
     * @param array<int, array{day: string, views: int, visitors: int}> $series
     * @return array{width: int, height: int, pad_left: int, plot_right: int, plot_bottom: int,
     *     points_views: string, points_visitors: string,
     *     grid: array<int, array{y: float, value: int}>, labels: array<int, array{x: float, text: string}>}
     */
    public static function lineChart(array $series): array
    {
        $count = count($series);
        $max = max(1, ...array_column($series, 'views'));
        $plotWidth = self::WIDTH - self::PAD_LEFT - self::PAD_RIGHT;
        $plotHeight = self::HEIGHT - self::PAD_TOP - self::PAD_BOTTOM;

        $x = fn (int $i): float => self::PAD_LEFT + ($count > 1 ? $i / ($count - 1) * $plotWidth : $plotWidth / 2);
        $y = fn (int $value): float => self::PAD_TOP + $plotHeight - ($value / $max) * $plotHeight;

        $pointsViews = [];
        $pointsVisitors = [];
        foreach (array_values($series) as $i => $row) {
            $pointsViews[] = round($x($i), 1) . ',' . round($y($row['views']), 1);
            $pointsVisitors[] = round($x($i), 1) . ',' . round($y($row['visitors']), 1);
        }

        $grid = [];
        for ($step = 0; $step <= 4; $step++) {
            $value = (int) round($max / 4 * $step);
            $grid[] = ['y' => round($y($value), 1), 'value' => $value];
        }

        $labelCount = max(1, min(6, $count));
        $labels = [];
        $seen = [];
        for ($i = 0; $i < $labelCount; $i++) {
            $index = $count > 1 ? (int) round($i / ($labelCount - 1) * ($count - 1)) : 0;
            if (isset($seen[$index])) {
                continue;
            }
            $seen[$index] = true;
            $labels[] = ['x' => round($x($index), 1), 'text' => substr($series[$index]['day'], 5)];
        }

        return [
            'width' => self::WIDTH, 'height' => self::HEIGHT,
            'pad_left' => self::PAD_LEFT, 'plot_right' => self::WIDTH - self::PAD_RIGHT, 'plot_bottom' => self::HEIGHT - self::PAD_BOTTOM,
            'points_views' => implode(' ', $pointsViews),
            'points_visitors' => implode(' ', $pointsVisitors),
            'grid' => $grid,
            'labels' => $labels,
        ];
    }
}
