<?php
namespace FacturaScripts\Plugins\AdminLTE\Lib;

class ChartHelper
{
    /**
     * Generates a Line Chart configuration for Chart.js
     *
     * @param string $id Canvas ID
     * @param array $labels X-axis labels
     * @param array $datasets Array of datasets (label, data, color)
     * @return string HTML/JS to render the chart
     */
    public static function lineChart($id, $labels, $datasets)
    {
        $jsLabels = json_encode($labels);
        $jsDatasets = [];

        foreach ($datasets as $ds) {
            $color = $ds['color'] ?? 'rgba(60,141,188,1)'; // AdminLTE default blue
            $label = $ds['label'] ?? '';
            $data = json_encode($ds['data']);

            $jsDatasets[] = "{
                label: '{$label}',
                fill: false,
                borderColor: '{$color}',
                pointBackgroundColor: '{$color}',
                pointRadius: 0,
                backgroundColor: '{$color}',
                data: {$data},
                lineTension: 0
            }";
        }

        $datasetsString = implode(',', $jsDatasets);

        return <<<HTML
        <canvas id="{$id}" style="height: 250px;"></canvas>
        <script>
            document.addEventListener("DOMContentLoaded", function() {
                var ctx = document.getElementById('{$id}').getContext('2d');
                new Chart(ctx, {
                    type: 'line',
                    data: {
                        labels: {$jsLabels},
                        datasets: [{$datasetsString}]
                    },
                    options: {
                        maintainAspectRatio: false,
                        responsive: true,
                        legend: {
                            display: true,
                            position: 'top',
                            align: 'end'
                        },
                        scales: {
                            xAxes: [{
                                gridLines: {
                                    display: false
                                },
                                ticks: {
                                    fontColor: '#aaaaaa',
                                    maxTicksLimit: 7
                                }
                            }],
                            yAxes: [{
                                gridLines: {
                                    display: true,
                                    color: '#f0f0f0',
                                    drawBorder: false
                                },
                                ticks: {
                                    fontColor: '#aaaaaa',
                                    beginAtZero: true,
                                    callback: function(value, index, values) {
                                        if(value >= 1000000) return (value/1000000).toFixed(1) + 'M';
                                        if(value >= 1000) return (value/1000).toFixed(1) + 'k';
                                        return value;
                                    }
                                }
                            }]
                        },
                        tooltips: {
                            mode: 'index',
                            intersect: false,
                        },
                        elements: {
                             line: {
                                 borderWidth: 2
                             }
                        }
                    }
                });
            });
        </script>
HTML;
    }
}
