#!/usr/bin/env python3
from __future__ import annotations

import argparse
import csv
import datetime as dt
import html
import json
import os
import re
import sys
import time
import urllib.error
import urllib.parse
import urllib.request
import xml.etree.ElementTree as ET
from collections import Counter, deque
from dataclasses import dataclass
from html.parser import HTMLParser
from typing import Any, Iterable


DEFAULT_USER_AGENT = (
    "SEO-Meta-Agent/0.1 (+https://example.local; automated SEO audit)"
)

ASSET_EXTENSIONS = {
    ".7z",
    ".avi",
    ".css",
    ".csv",
    ".doc",
    ".docx",
    ".gif",
    ".ico",
    ".jpeg",
    ".jpg",
    ".js",
    ".json",
    ".mp3",
    ".mp4",
    ".pdf",
    ".png",
    ".rar",
    ".svg",
    ".webm",
    ".webp",
    ".xls",
    ".xlsx",
    ".zip",
}

VI_STOPWORDS = {
    "anh",
    "ban",
    "bang",
    "bi",
    "boi",
    "cac",
    "cho",
    "co",
    "cua",
    "cung",
    "da",
    "dang",
    "de",
    "den",
    "duoc",
    "hay",
    "hon",
    "khi",
    "khong",
    "la",
    "lam",
    "mot",
    "nay",
    "nhieu",
    "nhung",
    "nhu",
    "nhung",
    "qua",
    "ra",
    "sau",
    "se",
    "tai",
    "the",
    "thi",
    "trong",
    "tu",
    "va",
    "vao",
    "ve",
    "voi",
    "và",
    "của",
    "các",
    "cho",
    "một",
    "những",
    "trong",
    "được",
    "không",
    "với",
    "này",
    "khi",
    "đến",
    "từ",
    "the",
    "and",
    "for",
    "with",
    "you",
    "your",
}

TOKEN_RE = re.compile(r"[^\W_]{3,}", re.UNICODE)


def utc_now_iso() -> str:
    return dt.datetime.now(dt.timezone.utc).replace(microsecond=0).isoformat()


def compact_spaces(value: str) -> str:
    return re.sub(r"\s+", " ", html.unescape(value or "")).strip()


def strip_fragment(url: str) -> str:
    parsed = urllib.parse.urlsplit(url)
    return urllib.parse.urlunsplit((parsed.scheme, parsed.netloc, parsed.path, parsed.query, ""))


def normalize_url(url: str, base_url: str | None = None) -> str | None:
    if not url:
        return None
    url = html.unescape(url.strip())
    if url.startswith(("mailto:", "tel:", "javascript:", "#")):
        return None
    if base_url:
        url = urllib.parse.urljoin(base_url, url)
    parsed = urllib.parse.urlsplit(strip_fragment(url))
    if parsed.scheme not in {"http", "https", "file"}:
        return None
    return urllib.parse.urlunsplit(parsed)


def same_site(url: str, root_url: str) -> bool:
    left = urllib.parse.urlsplit(url)
    right = urllib.parse.urlsplit(root_url)
    if left.scheme == "file" and right.scheme == "file":
        return True
    return left.netloc.lower() == right.netloc.lower()


def looks_like_page(url: str) -> bool:
    parsed = urllib.parse.urlsplit(url)
    suffix = os.path.splitext(parsed.path.lower())[1]
    return suffix not in ASSET_EXTENSIONS


def read_local_file_url(url: str) -> tuple[str, int, str, str]:
    parsed = urllib.parse.urlsplit(url)
    path = urllib.request.url2pathname(parsed.path)
    if os.name == "nt" and path.startswith("/") and re.match(r"^/[A-Za-z]:/", path):
        path = path[1:]
    with open(path, "rb") as handle:
        raw = handle.read()
    return raw.decode("utf-8", errors="replace"), 200, "text/html; charset=utf-8", url


def fetch_url(
    url: str,
    *,
    timeout: int,
    user_agent: str,
    max_bytes: int = 4_000_000,
) -> tuple[str, int, str, str]:
    if urllib.parse.urlsplit(url).scheme == "file":
        return read_local_file_url(url)

    request = urllib.request.Request(
        url,
        headers={
            "User-Agent": user_agent,
            "Accept": "text/html,application/xhtml+xml,application/xml;q=0.9,*/*;q=0.8",
        },
    )
    try:
        with urllib.request.urlopen(request, timeout=timeout) as response:
            raw = response.read(max_bytes)
            content_type = response.headers.get("content-type", "")
            status = getattr(response, "status", 200)
            final_url = response.geturl()
    except urllib.error.HTTPError as error:
        raw = error.read(max_bytes)
        content_type = error.headers.get("content-type", "")
        status = error.code
        final_url = error.geturl()

    charset = "utf-8"
    match = re.search(r"charset=([^;\s]+)", content_type, re.I)
    if match:
        charset = match.group(1).strip("\"'")
    return raw.decode(charset, errors="replace"), status, content_type, final_url


class PageHTMLParser(HTMLParser):
    def __init__(self, base_url: str):
        super().__init__(convert_charrefs=True)
        self.base_url = base_url
        self.title_parts: list[str] = []
        self.h1_parts: list[str] = []
        self.text_parts: list[str] = []
        self.links: list[str] = []
        self.meta_description = ""
        self.meta_keywords = ""
        self.og_title = ""
        self.og_description = ""
        self.canonical = ""
        self.lang = ""
        self._in_title = False
        self._in_h1 = False
        self._skip_depth = 0

    def handle_starttag(self, tag: str, attrs: list[tuple[str, str | None]]) -> None:
        tag = tag.lower()
        attr = {name.lower(): value or "" for name, value in attrs}

        if tag == "html":
            self.lang = attr.get("lang", self.lang)
        if tag == "title":
            self._in_title = True
        if tag == "h1":
            self._in_h1 = True
        if tag in {"head", "script", "style", "noscript", "svg", "canvas"}:
            self._skip_depth += 1

        if tag == "meta":
            name = attr.get("name", "").lower()
            prop = attr.get("property", "").lower()
            content = compact_spaces(attr.get("content", ""))
            if name == "description" and content:
                self.meta_description = content
            elif name == "keywords" and content:
                self.meta_keywords = content
            elif prop == "og:title" and content:
                self.og_title = content
            elif prop == "og:description" and content:
                self.og_description = content

        if tag == "link":
            rel = {item.lower() for item in attr.get("rel", "").split()}
            href = attr.get("href", "")
            if "canonical" in rel and href:
                normalized = normalize_url(href, self.base_url)
                if normalized:
                    self.canonical = normalized

        if tag == "a":
            normalized = normalize_url(attr.get("href", ""), self.base_url)
            if normalized:
                self.links.append(normalized)

        if tag in {"article", "br", "div", "h1", "h2", "h3", "li", "main", "p", "section"}:
            self.text_parts.append(" ")

    def handle_endtag(self, tag: str) -> None:
        tag = tag.lower()
        if tag == "title":
            self._in_title = False
        if tag == "h1":
            self._in_h1 = False
        if tag in {"head", "script", "style", "noscript", "svg", "canvas"} and self._skip_depth:
            self._skip_depth -= 1
        if tag in {"article", "div", "h1", "h2", "h3", "li", "main", "p", "section"}:
            self.text_parts.append(" ")

    def handle_data(self, data: str) -> None:
        text = compact_spaces(data)
        if not text:
            return
        if self._in_title:
            self.title_parts.append(text)
        if self._in_h1:
            self.h1_parts.append(text)
        if self._skip_depth:
            return
        self.text_parts.append(text)

    @property
    def title(self) -> str:
        return compact_spaces(" ".join(self.title_parts))

    @property
    def h1(self) -> str:
        return compact_spaces(" ".join(self.h1_parts))

    @property
    def text(self) -> str:
        return compact_spaces(" ".join(self.text_parts))


@dataclass
class PageSnapshot:
    url: str
    final_url: str
    status: int
    page_type: str
    title: str
    meta_description: str
    meta_keywords: str
    h1: str
    lang: str
    canonical: str
    word_count: int
    text: str
    links: list[str]


def parse_page(url: str, body: str, status: int, final_url: str) -> PageSnapshot:
    parser = PageHTMLParser(final_url or url)
    parser.feed(body)
    text = parser.text
    return PageSnapshot(
        url=url,
        final_url=final_url,
        status=status,
        page_type=classify_page(final_url or url),
        title=parser.title,
        meta_description=parser.meta_description,
        meta_keywords=parser.meta_keywords,
        h1=parser.h1,
        lang=parser.lang,
        canonical=parser.canonical,
        word_count=len(TOKEN_RE.findall(text)),
        text=text,
        links=parser.links,
    )


def classify_page(url: str) -> str:
    parsed = urllib.parse.urlsplit(url)
    path = parsed.path.strip("/").lower()
    if not path:
        return "home"
    segments = [segment for segment in path.split("/") if segment]
    category_markers = {"category", "categories", "tag", "tags", "danh-muc", "chuyen-muc"}
    article_markers = {"blog", "news", "tin-tuc", "bai-viet", "article", "posts", "post"}
    if any(segment in category_markers for segment in segments):
        return "category"
    if any(segment in article_markers for segment in segments):
        return "article"
    if re.search(r"/20\d{2}/\d{1,2}/", f"/{path}/") or path.endswith((".html", ".htm")):
        return "article"
    return "static"


def sitemap_candidates(start_url: str) -> list[str]:
    parsed = urllib.parse.urlsplit(start_url)
    if parsed.scheme == "file":
        return []
    root = urllib.parse.urlunsplit((parsed.scheme, parsed.netloc, "", "", ""))
    return [urllib.parse.urljoin(root, "/sitemap.xml"), urllib.parse.urljoin(root, "/sitemap_index.xml")]


def extract_xml_locs(xml_body: str) -> list[str]:
    try:
        root = ET.fromstring(xml_body.encode("utf-8"))
    except ET.ParseError:
        return []
    locs: list[str] = []
    for element in root.iter():
        if element.tag.lower().endswith("loc") and element.text:
            locs.append(compact_spaces(element.text))
    return locs


def discover_from_sitemaps(
    start_url: str,
    *,
    timeout: int,
    user_agent: str,
    max_pages: int,
    verbose: bool,
) -> list[str]:
    found_pages: list[str] = []
    seen_sitemaps: set[str] = set()
    queue: deque[str] = deque(sitemap_candidates(start_url))

    while queue and len(found_pages) < max_pages:
        sitemap_url = queue.popleft()
        if sitemap_url in seen_sitemaps:
            continue
        seen_sitemaps.add(sitemap_url)
        try:
            body, status, content_type, _ = fetch_url(
                sitemap_url, timeout=timeout, user_agent=user_agent
            )
        except Exception as exc:
            if verbose:
                print(f"[sitemap] skipped {sitemap_url}: {exc}", file=sys.stderr)
            continue
        if status >= 400:
            continue
        for loc in extract_xml_locs(body):
            normalized = normalize_url(loc)
            if not normalized or not same_site(normalized, start_url):
                continue
            if normalized.lower().endswith(".xml") and len(seen_sitemaps) < 50:
                queue.append(normalized)
            elif looks_like_page(normalized):
                found_pages.append(normalized)
                if len(found_pages) >= max_pages:
                    break

    return list(dict.fromkeys(found_pages))


def crawl_pages(
    start_url: str,
    *,
    timeout: int,
    user_agent: str,
    max_pages: int,
    delay: float,
    use_sitemap: bool,
    include_patterns: list[str],
    exclude_patterns: list[str],
    verbose: bool,
) -> list[PageSnapshot]:
    start_url = normalize_url(start_url) or start_url
    urls: list[str] = []
    if use_sitemap:
        urls = discover_from_sitemaps(
            start_url,
            timeout=timeout,
            user_agent=user_agent,
            max_pages=max_pages,
            verbose=verbose,
        )
    if not urls:
        urls = [start_url]

    snapshots: list[PageSnapshot] = []
    seen: set[str] = set()
    queue: deque[str] = deque(urls)

    while queue and len(snapshots) < max_pages:
        url = queue.popleft()
        normalized = normalize_url(url)
        if not normalized or normalized in seen or not same_site(normalized, start_url):
            continue
        if not looks_like_page(normalized):
            continue
        if include_patterns and not any(re.search(pattern, normalized) for pattern in include_patterns):
            continue
        if exclude_patterns and any(re.search(pattern, normalized) for pattern in exclude_patterns):
            continue
        seen.add(normalized)

        try:
            body, status, content_type, final_url = fetch_url(
                normalized, timeout=timeout, user_agent=user_agent
            )
        except Exception as exc:
            if verbose:
                print(f"[crawl] failed {normalized}: {exc}", file=sys.stderr)
            continue

        if "html" not in content_type.lower() and urllib.parse.urlsplit(normalized).scheme != "file":
            continue
        snapshot = parse_page(normalized, body, status, final_url)
        snapshots.append(snapshot)
        if verbose:
            print(f"[crawl] {len(snapshots):03d} {snapshot.status} {snapshot.final_url}", file=sys.stderr)

        if len(urls) == 1:
            for link in snapshot.links:
                link = normalize_url(link)
                if link and link not in seen and same_site(link, start_url) and looks_like_page(link):
                    queue.append(link)

        if delay:
            time.sleep(delay)

    return snapshots


def load_pages_file(path: str, start_url: str | None = None) -> list[str]:
    urls: list[str] = []
    with open(path, "r", encoding="utf-8-sig") as handle:
        for line in handle:
            value = line.strip()
            if not value or value.startswith("#"):
                continue
            normalized = normalize_url(value, start_url)
            if normalized:
                urls.append(normalized)
    return list(dict.fromkeys(urls))


def infer_keywords(text: str, max_keywords: int = 8) -> list[str]:
    tokens = [
        token.lower()
        for token in TOKEN_RE.findall(text)
        if token.lower() not in VI_STOPWORDS and len(token) >= 3
    ]
    if not tokens:
        return []
    candidates: Counter[str] = Counter(tokens)
    for size in (2, 3):
        for index in range(len(tokens) - size + 1):
            phrase_tokens = tokens[index : index + size]
            if len(set(phrase_tokens)) < size:
                continue
            candidates[" ".join(phrase_tokens)] += size + 1
    return [keyword for keyword, _ in candidates.most_common(max_keywords)]


def sentence_candidates(text: str) -> list[str]:
    chunks = re.split(r"(?<=[.!?。！？])\s+", text)
    return [compact_spaces(chunk) for chunk in chunks if len(compact_spaces(chunk)) >= 30]


def shorten(value: str, limit: int) -> str:
    value = compact_spaces(value)
    if len(value) <= limit:
        return value
    cut = value[: limit - 1].rsplit(" ", 1)[0]
    return compact_spaces(cut or value[: limit - 1])


def heuristic_suggestion(snapshot: PageSnapshot) -> dict[str, Any]:
    source_title = snapshot.h1 or snapshot.title or urllib.parse.urlsplit(snapshot.final_url).path.strip("/")
    source_title = compact_spaces(source_title.replace("-", " "))
    keywords = infer_keywords(" ".join([source_title, snapshot.meta_description, snapshot.text]), 8)
    primary_keyword = keywords[0] if keywords else shorten(source_title, 55)
    title = source_title
    if primary_keyword and primary_keyword.lower() not in title.lower():
        title = f"{primary_keyword.title()} - {source_title}"
    title = shorten(title, 62)

    description = ""
    for candidate in sentence_candidates(snapshot.meta_description + " " + snapshot.text):
        if 80 <= len(candidate) <= 165:
            description = candidate
            break
    if not description:
        description = shorten(snapshot.meta_description or snapshot.text, 155)
    if not description and primary_keyword:
        description = f"Thong tin chi tiet ve {primary_keyword}, duoc tong hop tu noi dung trang."

    return {
        "seo_title": title,
        "meta_description": description,
        "primary_keyword": primary_keyword,
        "secondary_keywords": keywords[1:8],
        "og_title": title,
        "og_description": description,
        "confidence": 0.45,
        "notes": "Heuristic draft only. Review before publishing.",
        "generator": "heuristic",
    }


def seo_schema() -> dict[str, Any]:
    return {
        "type": "object",
        "additionalProperties": False,
        "required": [
            "seo_title",
            "meta_description",
            "primary_keyword",
            "secondary_keywords",
            "og_title",
            "og_description",
            "confidence",
            "notes",
        ],
        "properties": {
            "seo_title": {"type": "string"},
            "meta_description": {"type": "string"},
            "primary_keyword": {"type": "string"},
            "secondary_keywords": {"type": "array", "items": {"type": "string"}},
            "og_title": {"type": "string"},
            "og_description": {"type": "string"},
            "confidence": {"type": "number"},
            "notes": {"type": "string"},
        },
    }


def extract_response_text(response: dict[str, Any]) -> str:
    if isinstance(response.get("output_text"), str):
        return response["output_text"]
    output = response.get("output", [])
    texts: list[str] = []
    for item in output:
        if not isinstance(item, dict):
            continue
        for content in item.get("content", []):
            if not isinstance(content, dict):
                continue
            if isinstance(content.get("text"), str):
                texts.append(content["text"])
    return "\n".join(texts).strip()


def call_openai_for_page(
    snapshot: PageSnapshot,
    *,
    api_key: str,
    model: str,
    timeout: int,
    max_content_chars: int,
) -> dict[str, Any]:
    excerpt = shorten(snapshot.text, max_content_chars)
    prompt_payload = {
        "url": snapshot.final_url or snapshot.url,
        "page_type": snapshot.page_type,
        "language_hint": snapshot.lang or "vi",
        "current_title": snapshot.title,
        "current_meta_description": snapshot.meta_description,
        "current_meta_keywords": snapshot.meta_keywords,
        "h1": snapshot.h1,
        "word_count": snapshot.word_count,
        "content_excerpt": excerpt,
    }
    instructions = (
        "You are a senior SEO editor for Vietnamese websites. "
        "Generate metadata in natural Vietnamese with accents when the source is Vietnamese. "
        "Do not invent facts, offers, prices, dates, locations, or guarantees that are not supported by the page content. "
        "Do not generate a legacy HTML meta keywords tag; generate target keywords only for editorial SEO guidance. "
        "Keep SEO titles concise, normally around 45 to 65 characters. "
        "Keep meta descriptions concise, normally around 120 to 165 characters. "
        "Prefer click-worthy clarity over keyword stuffing. "
        "Return only JSON that matches the schema."
    )
    body = {
        "model": model,
        "instructions": instructions,
        "input": "Optimize this page metadata. Page JSON:\n"
        + json.dumps(prompt_payload, ensure_ascii=False),
        "text": {
            "format": {
                "type": "json_schema",
                "name": "seo_meta_suggestion",
                "strict": True,
                "schema": seo_schema(),
            }
        },
    }
    request = urllib.request.Request(
        "https://api.openai.com/v1/responses",
        data=json.dumps(body).encode("utf-8"),
        headers={
            "Authorization": f"Bearer {api_key}",
            "Content-Type": "application/json",
        },
        method="POST",
    )
    with urllib.request.urlopen(request, timeout=timeout) as response:
        response_body = response.read().decode("utf-8", errors="replace")
    parsed = json.loads(response_body)
    text = extract_response_text(parsed)
    if not text:
        raise RuntimeError("OpenAI response did not contain output text")
    result = json.loads(text)
    result["generator"] = f"openai:{model}"
    return result


def has_vietnamese_diacritic(value: str) -> bool:
    return bool(re.search(r"[ăâđêôơưàáảãạằắẳẵặầấẩẫậèéẻẽẹềếểễệìíỉĩịòóỏõọồốổỗộờớởỡợùúủũụừứửữựỳýỷỹỵ]", value, re.I))


def keyword_count(text: str, keyword: str) -> int:
    if not keyword:
        return 0
    return text.lower().count(keyword.lower())


def validate_suggestion(
    suggestion: dict[str, Any],
    snapshot: PageSnapshot,
    seen_titles: set[str],
    seen_descriptions: set[str],
) -> tuple[int, str]:
    score = 100
    notes: list[str] = []
    title = compact_spaces(str(suggestion.get("seo_title", "")))
    description = compact_spaces(str(suggestion.get("meta_description", "")))
    primary = compact_spaces(str(suggestion.get("primary_keyword", "")))

    if len(title) < 30:
        score -= 12
        notes.append("SEO title is short")
    if len(title) > 70:
        score -= 15
        notes.append("SEO title is long")
    if len(description) < 90:
        score -= 12
        notes.append("Meta description is short")
    if len(description) > 175:
        score -= 15
        notes.append("Meta description is long")
    if not primary:
        score -= 20
        notes.append("Missing primary keyword")
    elif primary.lower() not in title.lower():
        score -= 8
        notes.append("Primary keyword not visible in title")
    if primary and keyword_count(title, primary) > 1:
        score -= 8
        notes.append("Possible keyword repetition in title")
    if primary and keyword_count(description, primary) > 2:
        score -= 8
        notes.append("Possible keyword repetition in description")
    if title.lower() in seen_titles:
        score -= 20
        notes.append("Duplicate generated title")
    if description.lower() in seen_descriptions:
        score -= 20
        notes.append("Duplicate generated description")
    if has_vietnamese_diacritic(snapshot.text) and not has_vietnamese_diacritic(title + " " + description):
        score -= 8
        notes.append("Vietnamese accents may be missing")
    if snapshot.page_type == "home":
        notes.append("Homepage should be manually reviewed")
    if snapshot.status >= 400:
        score -= 30
        notes.append(f"HTTP status {snapshot.status}")
    if snapshot.word_count < 120:
        score -= 10
        notes.append("Thin page content")

    score = max(0, min(100, score))
    if not notes:
        notes.append("OK")
    return score, "; ".join(notes)


def stringify_keywords(value: Any) -> str:
    if isinstance(value, list):
        return ", ".join(compact_spaces(str(item)) for item in value if compact_spaces(str(item)))
    return compact_spaces(str(value or ""))


def build_row(snapshot: PageSnapshot, suggestion: dict[str, Any], score: int, notes: str) -> dict[str, Any]:
    return {
        "url": snapshot.final_url or snapshot.url,
        "page_type": snapshot.page_type,
        "http_status": snapshot.status,
        "language": snapshot.lang,
        "canonical": snapshot.canonical,
        "word_count": snapshot.word_count,
        "current_title": snapshot.title,
        "current_meta_description": snapshot.meta_description,
        "current_meta_keywords": snapshot.meta_keywords,
        "h1": snapshot.h1,
        "suggested_seo_title": compact_spaces(str(suggestion.get("seo_title", ""))),
        "suggested_meta_description": compact_spaces(str(suggestion.get("meta_description", ""))),
        "primary_keyword": compact_spaces(str(suggestion.get("primary_keyword", ""))),
        "secondary_keywords": stringify_keywords(suggestion.get("secondary_keywords", [])),
        "suggested_og_title": compact_spaces(str(suggestion.get("og_title", ""))),
        "suggested_og_description": compact_spaces(str(suggestion.get("og_description", ""))),
        "validation_score": score,
        "validation_notes": notes,
        "generator": suggestion.get("generator", ""),
        "model_confidence": suggestion.get("confidence", ""),
        "model_notes": suggestion.get("notes", ""),
        "crawled_at": utc_now_iso(),
    }


def write_csv(rows: list[dict[str, Any]], out_path: str) -> None:
    if not rows:
        raise RuntimeError("No rows to write")
    parent = os.path.dirname(os.path.abspath(out_path))
    if parent:
        os.makedirs(parent, exist_ok=True)
    fieldnames = list(rows[0].keys())
    with open(out_path, "w", newline="", encoding="utf-8-sig") as handle:
        writer = csv.DictWriter(handle, fieldnames=fieldnames)
        writer.writeheader()
        writer.writerows(rows)


def compile_patterns(values: list[str] | None) -> list[str]:
    return values or []


def parse_args(argv: list[str]) -> argparse.Namespace:
    parser = argparse.ArgumentParser(
        description="Crawl a site and generate Vietnamese SEO metadata suggestions."
    )
    parser.add_argument("url", help="Site URL, page URL, or file:// URL to crawl.")
    parser.add_argument("--pages-file", help="Optional text file with one URL per line.")
    parser.add_argument("--out", default="seo_meta_suggestions.csv", help="CSV output path.")
    parser.add_argument("--max-pages", type=int, default=25, help="Maximum pages to process.")
    parser.add_argument("--delay", type=float, default=0.4, help="Delay between page requests.")
    parser.add_argument("--timeout", type=int, default=30, help="HTTP/API timeout in seconds.")
    parser.add_argument("--user-agent", default=DEFAULT_USER_AGENT, help="Crawler user agent.")
    parser.add_argument("--model", default=os.getenv("OPENAI_MODEL", "gpt-5-mini"), help="OpenAI model.")
    parser.add_argument("--dry-run", action="store_true", help="Use heuristic generation and skip OpenAI API calls.")
    parser.add_argument("--no-sitemap", dest="use_sitemap", action="store_false", help="Skip sitemap discovery.")
    parser.add_argument("--include", action="append", help="Regex that URLs must match. Can be repeated.")
    parser.add_argument("--exclude", action="append", help="Regex for URLs to skip. Can be repeated.")
    parser.add_argument("--max-content-chars", type=int, default=8500, help="Max page text sent to the LLM.")
    parser.add_argument("--verbose", action="store_true", help="Print crawl progress to stderr.")
    parser.set_defaults(use_sitemap=True)
    return parser.parse_args(argv)


def main(argv: list[str] | None = None) -> int:
    args = parse_args(argv or sys.argv[1:])
    include_patterns = compile_patterns(args.include)
    exclude_patterns = compile_patterns(args.exclude)

    if args.pages_file:
        page_urls = load_pages_file(args.pages_file, args.url)
        snapshots = []
        for url in page_urls[: args.max_pages]:
            try:
                body, status, content_type, final_url = fetch_url(
                    url, timeout=args.timeout, user_agent=args.user_agent
                )
            except Exception as exc:
                print(f"[crawl] failed {url}: {exc}", file=sys.stderr)
                continue
            if "html" not in content_type.lower() and urllib.parse.urlsplit(url).scheme != "file":
                continue
            snapshots.append(parse_page(url, body, status, final_url))
            if args.delay:
                time.sleep(args.delay)
    else:
        snapshots = crawl_pages(
            args.url,
            timeout=args.timeout,
            user_agent=args.user_agent,
            max_pages=args.max_pages,
            delay=args.delay,
            use_sitemap=args.use_sitemap,
            include_patterns=include_patterns,
            exclude_patterns=exclude_patterns,
            verbose=args.verbose,
        )

    if not snapshots:
        print("No pages were crawled.", file=sys.stderr)
        return 2

    api_key = os.getenv("OPENAI_API_KEY", "")
    use_openai = bool(api_key) and not args.dry_run
    if not use_openai and not args.dry_run:
        print("OPENAI_API_KEY is missing; falling back to --dry-run heuristics.", file=sys.stderr)

    rows: list[dict[str, Any]] = []
    seen_titles: set[str] = set()
    seen_descriptions: set[str] = set()

    for snapshot in snapshots:
        suggestion: dict[str, Any]
        if use_openai:
            try:
                suggestion = call_openai_for_page(
                    snapshot,
                    api_key=api_key,
                    model=args.model,
                    timeout=args.timeout,
                    max_content_chars=args.max_content_chars,
                )
            except Exception as exc:
                suggestion = heuristic_suggestion(snapshot)
                suggestion["notes"] = f"OpenAI failed, heuristic fallback used: {exc}"
        else:
            suggestion = heuristic_suggestion(snapshot)

        score, notes = validate_suggestion(suggestion, snapshot, seen_titles, seen_descriptions)
        row = build_row(snapshot, suggestion, score, notes)
        if row["suggested_seo_title"]:
            seen_titles.add(row["suggested_seo_title"].lower())
        if row["suggested_meta_description"]:
            seen_descriptions.add(row["suggested_meta_description"].lower())
        rows.append(row)

    write_csv(rows, args.out)
    print(f"Wrote {len(rows)} row(s) to {args.out}")
    if not use_openai:
        print("Generated with heuristic mode. Set OPENAI_API_KEY and rerun without --dry-run for LLM suggestions.")
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
