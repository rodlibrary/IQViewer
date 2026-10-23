"""
CCDT chart render service.

POST /render  - JSON in (aggregated series), PNG out
GET  /health

Only the Laravel app container should reach this service (it is not published
to the host in docker-compose). Set CHARTS_SERVICE_TOKEN on both sides to also
require a shared secret.
"""
import hmac
import os
import threading
from typing import List, Optional, Union

from fastapi import FastAPI, Header, HTTPException, Response
from pydantic import BaseModel, Field

import render

TOKEN = os.environ.get("CHARTS_SERVICE_TOKEN", "")
# matplotlib uses a lot of memory per figure; cap concurrent renders
_slots = threading.BoundedSemaphore(int(os.environ.get("CHARTS_MAX_CONCURRENT", "4")))

app = FastAPI(title="CCDT chart service", docs_url=None, redoc_url=None, openapi_url=None)


class Series(BaseModel):
    name: str = Field(max_length=200)
    values: List[Optional[float]]


class Chart(BaseModel):
    chart: str
    title: str = Field(default="", max_length=200)
    x_kind: str = "category"
    bucket: Optional[str] = None
    x: List[Union[str, int, float]]
    x_label: str = Field(default="", max_length=200)
    y_label: str = Field(default="", max_length=200)
    footnote: str = Field(default="", max_length=300)
    series: List[Series]


@app.get("/health")
def health():
    return {"ok": True}


@app.post("/render")
def render_chart(body: Chart, x_charts_token: Optional[str] = Header(default=None)):
    if TOKEN and not hmac.compare_digest(TOKEN, x_charts_token or ""):
        raise HTTPException(status_code=401, detail="bad token")
    if not _slots.acquire(timeout=20):
        raise HTTPException(status_code=503, detail="chart service is busy")
    try:
        png = render.render_png(body.model_dump())
    except render.RenderError as e:
        raise HTTPException(status_code=422, detail=str(e))
    except (ValueError, KeyError, IndexError) as e:
        # e.g. a time label that is not YYYYMM
        raise HTTPException(status_code=422, detail="could not draw this data: %s" % e)
    finally:
        _slots.release()
    return Response(content=png, media_type="image/png")
