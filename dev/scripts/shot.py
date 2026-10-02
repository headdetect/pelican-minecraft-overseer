# Logs in to the dev panel and screenshots one page. dev/shot runs this inside
# the Playwright container.
import argparse
import os

from playwright.sync_api import sync_playwright

parser = argparse.ArgumentParser()
parser.add_argument("base")
parser.add_argument("path")
parser.add_argument("out")
parser.add_argument("--click", action="append", default=[])
parser.add_argument("--wait", type=int, default=1500)
args = parser.parse_args()

# The session cookie is kept between runs, so most runs skip the login.
state = os.path.join(os.path.dirname(args.out), ".auth.json")

with sync_playwright() as p:
    browser = p.chromium.launch()
    context = browser.new_context(
        viewport={"width": 1440, "height": 900},
        storage_state=state if os.path.exists(state) else None,
    )
    page = context.new_page()
    problems = []
    page.on("console", lambda m: m.type in ("error", "warning") and problems.append(f"console.{m.type}: {m.text}"))
    page.on("pageerror", lambda e: problems.append(f"pageerror: {e}"))

    response = page.goto(args.base + args.path, wait_until="load")
    if "/login" in page.url:
        page.locator("input[type=email], input[type=text]").first.fill("admin@overseer.test")
        page.locator("input[type=password]").fill("overseer")
        page.locator("button[type=submit]").click()
        page.wait_for_url(lambda url: "/login" not in url)
        context.storage_state(path=state)
        response = page.goto(args.base + args.path, wait_until="load")

    for text in args.click:
        page.get_by_text(text, exact=True).locator("visible=true").first.click()
        page.wait_for_load_state("load")
        page.wait_for_timeout(500)

    page.wait_for_timeout(args.wait)
    page.screenshot(path=args.out, full_page=True)

    print(f"shot: {response.status if response else '?'} {page.url}")
    for line in problems:
        print(line)
    browser.close()
