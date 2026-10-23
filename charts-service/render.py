"""
Draws CCDT charts with matplotlib.

Input is the already-aggregated result of one SQL query (see
app/Libraries/Charts/ChartQueryBuilder.php::shape) - never raw records:

    {
      "chart": "line|bar|hbar|stacked|hist|heatmap",
      "title": "...", "x_label": "...", "y_label": "...", "footnote": "...",
      "x_kind": "time|category|number", "bucket": "month|year|null",
      "x": ["201701", ...],                       # labels, one per column
      "series": [{"name": "usmail", "values": [12, null, 7, ...]}, ...]
    }

The Figure API is used instead of pyplot so there is no global state and
rendering is safe from several threads.

Colors: the validated categorical palette (slots in fixed order, never cycled),
a one-hue blue ramp for the heatmap, and recessive grid / axes. Series beyond
MAX_SERIES are rejected: the PHP side already folds them into "Other", which is
drawn in neutral gray.
"""
import io
import math
import datetime as dt

from matplotlib.backends.backend_agg import FigureCanvasAgg
from matplotlib.colors import LinearSegmentedColormap
from matplotlib.figure import Figure
from matplotlib.ticker import FuncFormatter, MaxNLocator
import matplotlib.dates as mdates

CHARTS = ("line", "bar", "hbar", "stacked", "hist", "heatmap")

MAX_X = 2500
MAX_SERIES = 12
MAX_CELLS = 5000

SURFACE = "#fcfcfb"
INK = "#0b0b0b"
INK2 = "#52514e"
MUTED = "#898781"
GRID = "#e1e0d9"
BASELINE = "#c3c2b7"
# categorical slots 1-8 (validated: adjacent CVD dE >= 9.1, normal-vision dE >= 19.6)
CATEGORICAL = ["#2a78d6", "#eb6834", "#1baf7a", "#eda100", "#e87ba4", "#008300", "#6250d6", "#e34948"]
OTHER_COLOR = "#898781"
# sequential blue, steps 100 -> 700
SEQ = ["#cde2fb", "#9ec5f4", "#6da7ec", "#3987e5", "#256abf", "#184f95", "#0d366b"]
HEAT_MISSING = "#f0efec"


class RenderError(ValueError):
    """The payload cannot be drawn; the message is safe to return to the caller."""


def _color(i, name):
    return OTHER_COLOR if name == "Other" else CATEGORICAL[i % len(CATEGORICAL)]


def _validate(p):
    if p.get("chart") not in CHARTS:
        raise RenderError("unknown chart type")
    x = p.get("x") or []
    series = p.get("series") or []
    if not x or not series:
        raise RenderError("nothing to draw")
    if len(x) > MAX_X:
        raise RenderError("too many points (%d, max %d)" % (len(x), MAX_X))
    if len(series) > MAX_SERIES:
        raise RenderError("too many series (%d, max %d)" % (len(series), MAX_SERIES))
    if len(x) * len(series) > MAX_CELLS:
        raise RenderError("too many cells")
    for s in series:
        if len(s["values"]) != len(x):
            raise RenderError("series '%s' has %d values for %d x labels" % (s["name"], len(s["values"]), len(x)))
    if p["chart"] in ("stacked", "heatmap") and len(series) < 2 and p["chart"] == "heatmap":
        raise RenderError("a heatmap needs at least two rows")


def _num(v):
    return float("nan") if v is None else float(v)


def _time_labels(x, bucket):
    """'201703' -> date(2017, 3, 1); '2017' -> date(2017, 1, 1)."""
    out = []
    for k in x:
        k = str(k)
        if bucket == "year":
            out.append(dt.date(int(k), 1, 1))
        else:
            out.append(dt.date(int(k[:4]), int(k[4:6]), 1))
    return out


def _text_labels(x, kind, bucket):
    if kind == "time":
        if bucket == "year":
            return [str(k) for k in x]
        return ["%s-%s" % (str(k)[:4], str(k)[4:6]) for k in x]
    return [str(k) for k in x]


def _int_axis(series):
    for s in series:
        for v in s["values"]:
            if v is not None and abs(v - round(v)) > 1e-9:
                return False
    return True


def _value_axis(axis, series):
    integer = _int_axis(series)
    axis.set_major_formatter(_fmt(integer))
    axis.set_major_locator(MaxNLocator(nbins=6, integer=integer))


def _fmt(integer):
    if integer:
        return FuncFormatter(lambda v, _: "{:,.0f}".format(v))
    return FuncFormatter(lambda v, _: "{:,.1f}".format(v))


def _chrome(fig, ax, p, grid_axis="y"):
    fig.patch.set_facecolor(SURFACE)
    ax.set_facecolor(SURFACE)
    fig.suptitle(p.get("title") or "", x=0.01, y=0.99, ha="left", va="top", fontsize=12.5, fontweight="bold", color=INK)
    for side in ("top", "right"):
        ax.spines[side].set_visible(False)
    for side in ("left", "bottom"):
        ax.spines[side].set_color(BASELINE)
    ax.tick_params(colors=MUTED, length=0, labelsize=9)
    if grid_axis:
        ax.grid(axis=grid_axis, color=GRID, linewidth=0.8)
        ax.set_axisbelow(True)
    if p.get("footnote"):
        fig.text(0.01, 0.012, p["footnote"], ha="left", va="bottom", fontsize=8, color=MUTED)


def _legend(ax, series):
    handles, labels = ax.get_legend_handles_labels()
    if len(series) >= 2 and handles:
        leg = ax.legend(handles, labels, frameon=False, ncol=min(len(labels), 4), loc="lower left",
                        bbox_to_anchor=(0, 1.0), fontsize=9, handlelength=1.4, borderaxespad=0.2)
        for t in leg.get_texts():
            t.set_color(INK2)


def _thin(ax, n, axis="x", target=12):
    """Show about `target` category labels on a crowded axis."""
    if n <= target:
        return
    step = int(math.ceil(n / float(target)))
    ticks = ax.get_xticks() if axis == "x" else ax.get_yticks()
    labels = [t.get_text() for t in (ax.get_xticklabels() if axis == "x" else ax.get_yticklabels())]
    keep = [i for i in range(n) if i % step == 0]
    if axis == "x":
        ax.set_xticks(keep)
        ax.set_xticklabels([labels[i] if i < len(labels) else "" for i in keep])


def _direct_labels(ax, xs, series, colors):
    """Label line ends (<= 4 series), pushing labels apart so they never overlap."""
    if len(series) > 4:
        return
    ends = []
    for s, c in zip(series, colors):
        last = None
        for i in range(len(s["values"]) - 1, -1, -1):
            if s["values"][i] is not None:
                last = i
                break
        if last is not None:
            ends.append([s["values"][last], xs[last], s["name"], c])
    if not ends:
        return
    ymin, ymax = ax.get_ylim()
    gap = (ymax - ymin) * 0.055
    ends.sort(key=lambda e: e[0])
    pos = [e[0] for e in ends]
    for i in range(1, len(pos)):
        if pos[i] - pos[i - 1] < gap:
            pos[i] = pos[i - 1] + gap
    for e, y in zip(ends, pos):
        ax.plot([e[1]], [e[0]], marker="o", markersize=5, color=e[3], markeredgecolor=SURFACE, markeredgewidth=1.5, linestyle="none")
        ax.annotate(e[2], xy=(e[1], e[0]), xytext=(8, (y - e[0]) / (ymax - ymin) * ax.get_window_extent().height * 72.0 / ax.figure.dpi),
                    textcoords="offset points", va="center", fontsize=8.5, color=INK2, annotation_clip=False)


def _line(fig, ax, p):
    series = p["series"]
    xs = _time_labels(p["x"], p.get("bucket")) if p["x_kind"] == "time" else [float(k) for k in p["x"]]
    colors = [_color(i, s["name"]) for i, s in enumerate(series)]
    for s, c in zip(series, colors):
        ax.plot(xs, [_num(v) for v in s["values"]], color=c, linewidth=2, label=s["name"], solid_capstyle="round")
    if len(xs) == 1:
        for s, c in zip(series, colors):
            ax.plot(xs, [_num(v) for v in s["values"]], marker="o", markersize=6, color=c, linestyle="none")
    ax.set_ylim(bottom=0)
    ax.yaxis.set_major_formatter(_fmt(_int_axis(series)))
    ax.yaxis.set_major_locator(MaxNLocator(nbins=6, integer=_int_axis(series)))
    if p["x_kind"] == "time":
        loc = mdates.AutoDateLocator(minticks=4, maxticks=10)
        ax.xaxis.set_major_locator(loc)
        ax.xaxis.set_major_formatter(mdates.ConciseDateFormatter(loc))
    ax.margins(x=0.02)
    _direct_labels(ax, xs, series, colors)
    if len(series) >= 2:
        _legend(ax, series)


def _bar(fig, ax, p, horizontal=False):
    s = p["series"][0]
    labels = _text_labels(p["x"], p["x_kind"], p.get("bucket"))
    vals = [0 if v is None else v for v in s["values"]]
    pos = list(range(len(vals)))
    color = CATEGORICAL[0]
    if horizontal:
        ax.barh(pos, vals, color=color, height=0.66, edgecolor=SURFACE, linewidth=1)
        ax.set_yticks(pos)
        ax.set_yticklabels(labels)
        ax.invert_yaxis()
        ax.grid(axis="y", visible=False)
        ax.grid(axis="x", color=GRID, linewidth=0.8)
        _value_axis(ax.xaxis, [s])
        top = max(vals) if vals else 1
        for i, v in enumerate(vals):
            ax.text(v + top * 0.01, i, "{:,.0f}".format(v) if _int_axis([s]) else "{:,.1f}".format(v),
                    va="center", ha="left", fontsize=8.5, color=INK2)
        ax.set_xlim(0, top * 1.12 if top else 1)
        ax.tick_params(axis="y", labelsize=9)
    else:
        dense = len(vals) > 40
        ax.bar(pos, vals, color=color, width=0.92 if dense else 0.7,
               edgecolor="none" if dense else SURFACE, linewidth=0 if dense else 1)
        ax.set_xticks(pos)
        ax.set_xticklabels(labels, rotation=45 if len(labels) > 8 else 0, ha="right" if len(labels) > 8 else "center")
        _thin(ax, len(labels))
        _value_axis(ax.yaxis, [s])
        ax.set_ylim(bottom=0)


def _stacked(fig, ax, p):
    series = p["series"]
    labels = _text_labels(p["x"], p["x_kind"], p.get("bucket"))
    pos = list(range(len(labels)))
    bottoms = [0.0] * len(labels)
    # a 1px surface gap between segments reads well up to ~40 bars; beyond that it
    # would eat the bars, so crowded charts draw touching bars with no outline
    dense = len(labels) > 40
    for i, s in enumerate(series):
        vals = [0 if v is None else v for v in s["values"]]
        ax.bar(pos, vals, bottom=bottoms, color=_color(i, s["name"]), width=0.92 if dense else 0.8,
               edgecolor="none" if dense else SURFACE, linewidth=0 if dense else 1, label=s["name"])
        bottoms = [b + v for b, v in zip(bottoms, vals)]
    ax.set_xticks(pos)
    ax.set_xticklabels(labels, rotation=45 if len(labels) > 8 else 0, ha="right" if len(labels) > 8 else "center")
    _thin(ax, len(labels))
    _value_axis(ax.yaxis, series)
    ax.set_ylim(bottom=0)
    _legend(ax, series)


def _hist(fig, ax, p):
    s = p["series"][0]
    xs = [float(k) for k in p["x"]]
    vals = [0 if v is None else v for v in s["values"]]
    width = (xs[1] - xs[0]) if len(xs) > 1 else 1.0
    ax.bar(xs, vals, width=width * 0.94, align="edge", color=CATEGORICAL[0], edgecolor=SURFACE, linewidth=0.5)
    _value_axis(ax.yaxis, [s])
    ax.xaxis.set_major_locator(MaxNLocator(nbins=10, integer=True))
    ax.set_ylim(bottom=0)
    ax.margins(x=0.01)


def _heatmap(fig, ax, p):
    series = p["series"]
    labels = _text_labels(p["x"], p["x_kind"], p.get("bucket"))
    import numpy as np
    data = np.array([[_num(v) for v in s["values"]] for s in series], dtype=float)
    cmap = LinearSegmentedColormap.from_list("ccdt_seq", SEQ)
    cmap.set_bad(HEAT_MISSING)
    masked = np.ma.masked_invalid(data)
    im = ax.imshow(masked, aspect="auto", cmap=cmap, interpolation="nearest")
    ax.set_xticks(range(len(labels)))
    ax.set_xticklabels(labels, rotation=45 if len(labels) > 8 else 0, ha="right" if len(labels) > 8 else "center")
    _thin(ax, len(labels))
    ax.set_yticks(range(len(series)))
    ax.set_yticklabels([s["name"] for s in series])
    for side in ("left", "bottom"):
        ax.spines[side].set_visible(False)
    ax.grid(False)
    cb = fig.colorbar(im, ax=ax, fraction=0.025, pad=0.02)
    cb.outline.set_visible(False)
    cb.ax.tick_params(colors=MUTED, labelsize=8, length=0)
    _value_axis(cb.ax.yaxis, series)
    if data.size <= 120:
        hi = np.nanmax(data) if np.isfinite(data).any() else 1
        for r in range(data.shape[0]):
            for c in range(data.shape[1]):
                v = data[r, c]
                if np.isfinite(v):
                    ax.text(c, r, "{:,.0f}".format(v), ha="center", va="center", fontsize=8,
                            color="#ffffff" if v > hi * 0.55 else INK)


def render_png(p, dpi=110):
    """Return PNG bytes for a payload dict (see module docstring)."""
    _validate(p)
    chart = p["chart"]
    if chart == "hbar":
        height = min(max(3.6, 0.28 * len(p["x"]) + 1.2), 12.0)
    elif chart == "heatmap":
        height = 4.4
    else:
        height = 3.9
    fig = Figure(figsize=(8.4, height), dpi=dpi)
    FigureCanvasAgg(fig)
    ax = fig.add_subplot(111)

    _chrome(fig, ax, p, grid_axis=None if chart == "heatmap" else ("x" if chart == "hbar" else "y"))
    if chart == "line":
        _line(fig, ax, p)
    elif chart == "bar":
        _bar(fig, ax, p, horizontal=False)
    elif chart == "hbar":
        _bar(fig, ax, p, horizontal=True)
    elif chart == "stacked":
        _stacked(fig, ax, p)
    elif chart == "hist":
        _hist(fig, ax, p)
    else:
        _heatmap(fig, ax, p)

    # axis titles: the measure goes on the value axis, the field on the category axis
    if chart == "hbar":
        ax.set_xlabel(p.get("y_label") or "", color=INK2, fontsize=9)
    else:
        ax.set_xlabel(p.get("x_label") or "", color=INK2, fontsize=9)
        if chart != "heatmap":
            ax.set_ylabel(p.get("y_label") or "", color=INK2, fontsize=9)

    fig.tight_layout(rect=(0, 0.03 if p.get("footnote") else 0, 1, 0.965))
    buf = io.BytesIO()
    fig.savefig(buf, format="png", facecolor=SURFACE)
    return buf.getvalue()
