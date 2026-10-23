import os
import sys

import pytest
from fastapi.testclient import TestClient

sys.path.insert(0, os.path.join(os.path.dirname(__file__), ".."))
os.environ.setdefault("MPLBACKEND", "Agg")

import app as service  # noqa: E402
import render  # noqa: E402

PNG = b"\x89PNG\r\n\x1a\n"
MONTHS = ["201701", "201702", "201703", "201704", "201705", "201706"]


def payload(chart, **kw):
    base = {
        "chart": chart, "title": "Test", "x_label": "X", "y_label": "Records", "footnote": "",
        "x_kind": "category", "bucket": None,
        "x": ["a", "b", "c"], "series": [{"name": "Records", "values": [30, 20, 10]}],
    }
    base.update(kw)
    return base


CASES = {
    "line": payload("line", x_kind="time", bucket="month", x=MONTHS, series=[
        {"name": "usmail", "values": [10, 12, None, 14, 15, 16]},
        {"name": "imail", "values": [10, 12, 13, 14, 15, 16]},   # identical end values: labels must not collide
        {"name": "Other", "values": [1, 2, 3, 4, 5, 6]}]),
    "line_year": payload("line", x_kind="time", bucket="year", x=["2015", "2016", "2017"],
                         series=[{"name": "Records", "values": [5, 6, 7]}]),
    "line_single_point": payload("line", x_kind="time", bucket="month", x=["201701"], series=[{"name": "Records", "values": [5]}]),
    "bar": payload("bar"),
    "bar_time": payload("bar", x_kind="time", bucket="year", x=["2015", "2016", "2017"]),
    "hbar": payload("hbar", x=["COMM-%02d" % i for i in range(20)], series=[{"name": "Records", "values": list(range(20, 0, -1))}]),
    "stacked": payload("stacked", x_kind="time", bucket="month", x=MONTHS, series=[
        {"name": "C", "values": [5, 6, 7, 8, 9, 10]}, {"name": "P", "values": [1, 2, None, 4, 5, 6]}]),
    "hist": payload("hist", x_kind="number", x=["0", "7", "14", "21"], series=[{"name": "Records", "values": [40, 25, 10, 2]}]),
    "heatmap": payload("heatmap", x=["a", "b", "c"], series=[
        {"name": "r1", "values": [1, 2, 3]}, {"name": "r2", "values": [4, None, 6]}]),
    "avg_decimals": payload("bar", series=[{"name": "Average", "values": [1.5, 2.25, 0.75]}]),
}


@pytest.mark.parametrize("name", sorted(CASES))
def test_every_chart_type_renders_a_png(name):
    png = render.render_png(CASES[name])
    assert png.startswith(PNG) and len(png) > 3000


def test_footnote_and_title_render():
    png = render.render_png(payload("bar", footnote="3 cell(s) with fewer than 10 records are hidden"))
    assert png.startswith(PNG)


@pytest.mark.parametrize("bad", [
    payload("pie"),
    payload("bar", x=[], series=[]),
    payload("bar", series=[{"name": "s", "values": [1, 2]}]),                       # wrong length
    payload("stacked", series=[{"name": "s%d" % i, "values": [1, 2, 3]} for i in range(13)]),  # too many series
    payload("heatmap"),                                                              # one row only
    payload("bar", x=[str(i) for i in range(render.MAX_X + 1)], series=[{"name": "s", "values": [1] * (render.MAX_X + 1)}]),
])
def test_bad_payloads_are_rejected(bad):
    with pytest.raises(render.RenderError):
        render.render_png(bad)


client = TestClient(service.app)


def test_health():
    assert client.get("/health").json() == {"ok": True}


def test_render_endpoint_returns_png():
    r = client.post("/render", json=CASES["bar"])
    assert r.status_code == 200 and r.headers["content-type"] == "image/png" and r.content.startswith(PNG)


def test_render_endpoint_reports_bad_chart_as_422():
    r = client.post("/render", json=payload("pie"))
    assert r.status_code == 422 and "unknown chart" in r.json()["detail"]


def test_bad_time_label_is_422_not_500():
    r = client.post("/render", json=payload("line", x_kind="time", bucket="month", x=["not-a-date"]))
    assert r.status_code == 422


def test_token_is_enforced_when_configured(monkeypatch):
    monkeypatch.setattr(service, "TOKEN", "s3cret")
    assert client.post("/render", json=CASES["bar"]).status_code == 401
    assert client.post("/render", json=CASES["bar"], headers={"X-Charts-Token": "nope"}).status_code == 401
    assert client.post("/render", json=CASES["bar"], headers={"X-Charts-Token": "s3cret"}).status_code == 200


def test_docs_endpoints_are_off():
    assert client.get("/docs").status_code == 404
    assert client.get("/openapi.json").status_code == 404
