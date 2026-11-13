/**
 * Chart.js — только нужные части (линия, столбцы, кольцо), чтобы сборка оставалась небольшой.
 * Загружается отдельным файлом и только на страницах, где есть графики (см. charts.js).
 */
import {
    Chart,
    LineController,
    BarController,
    DoughnutController,
    LineElement,
    PointElement,
    BarElement,
    ArcElement,
    CategoryScale,
    LinearScale,
    Tooltip,
    Legend,
    Filler,
} from 'chart.js';

Chart.register(LineController, BarController, DoughnutController, LineElement, PointElement, BarElement, ArcElement, CategoryScale, LinearScale, Tooltip, Legend, Filler);

export default Chart;
