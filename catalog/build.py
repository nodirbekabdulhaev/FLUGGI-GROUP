import asyncio,pathlib
from playwright.async_api import async_playwright
async def m():
    async with async_playwright() as p:
        b=await p.chromium.launch(executable_path='/opt/pw-browsers/chromium')
        pg=await b.new_page();await pg.goto('file://'+str(pathlib.Path('catalog.html').resolve()))
        await pg.wait_for_timeout(500)
        await pg.pdf(path='Palma_Group_catalog.pdf',prefer_css_page_size=True,print_background=True)
        await b.close()
asyncio.run(m())
