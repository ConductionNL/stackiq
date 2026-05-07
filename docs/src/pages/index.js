/**
 * SoftwareCatalog landing page.
 *
 * Composes the brand <DetailHero> + <WidgetShelf> from
 * @conduction/docusaurus-preset/components, mirroring the connext page
 * at sites/www/src/pages/apps/softwarecatalog.mdx.
 *
 * Written as .js (not .mdx) because the docs site has the docs plugin
 * pointed at `path: './'`, and an MDX file in src/pages/ trips the
 * MDX-ESM parser even with the docs plugin's `src/**` exclude — likely
 * a quirk of how mdx-loader's micromark stack reuses parser state
 * across files in this Docusaurus 3.10 + this preset combination.
 * Authoring the page in JSX keeps the same component composition.
 */

import React from 'react';
import Layout from '@theme/Layout';
import {
  DetailHero,
  WidgetShelf,
  AppMock,
} from '@conduction/docusaurus-preset/components';

/* Catalog-grid icon. Identical shape to the mydash icon by design:
   the tile-grid motif reads as "rows in a register" for both apps
   (catalog of items vs. catalog of widgets) and ties the two product
   surfaces together visually. Cited from the connext detail page at
   sites/www/src/pages/apps/softwarecatalog.mdx. */
const SOFTWARECATALOG_ICON = (
  <svg viewBox="0 0 24 24">
    <rect x="3" y="3" width="7" height="9" />
    <rect x="14" y="3" width="7" height="5" />
    <rect x="14" y="12" width="7" height="9" />
    <rect x="3" y="16" width="7" height="5" />
  </svg>
);

const TAGLINE = (
  <>
    IT-asset management on your <span className="next-blue">Nextcloud</span>. A
    central register of every application, licence, contract, and dependency in
    your organisation. Ideal for the IT department that wants to know what runs
    where.
  </>
);

function RenewalsPanel() {
  const rows = [
    { w: '70%', accent: true },
    { w: '60%' },
    { w: '55%' },
    { w: '50%' },
  ];
  return (
    <div style={{ display: 'flex', flexDirection: 'column', gap: 6 }}>
      <div
        style={{
          display: 'flex',
          alignItems: 'baseline',
          gap: 6,
          marginBottom: 4,
        }}
      >
        <div
          style={{
            fontFamily: 'var(--conduction-typography-font-family-code)',
            fontSize: 28,
            fontWeight: 700,
            color: 'var(--c-orange-knvb)',
          }}
        >
          5
        </div>
        <div
          style={{
            fontFamily: 'var(--conduction-typography-font-family-code)',
            fontSize: 11,
            letterSpacing: '0.06em',
            textTransform: 'uppercase',
            color: 'var(--c-cobalt-400)',
          }}
        >
          this month
        </div>
      </div>
      {rows.map((row, i) => (
        <div
          key={i}
          style={{ display: 'flex', alignItems: 'center', gap: 6 }}
        >
          <span
            style={{
              width: 8,
              height: 9,
              clipPath: 'var(--hex-pointy-top)',
              background: row.accent
                ? 'var(--c-orange-knvb)'
                : 'var(--c-cobalt-300)',
              flexShrink: 0,
            }}
          />
          <div
            style={{
              flex: 1,
              height: 4,
              background: 'var(--c-cobalt-200)',
              borderRadius: 1,
              width: row.w,
            }}
          />
          <div
            style={{
              height: 4,
              width: 30,
              background: 'var(--c-cobalt-100)',
              borderRadius: 1,
            }}
          />
        </div>
      ))}
    </div>
  );
}

function InventoryPanel() {
  const rows = [
    { label: 'productivity', val: '70%', tone: 'var(--c-cobalt-300)' },
    { label: 'security', val: '50%', tone: 'var(--c-mint-500)' },
    { label: 'devtools', val: '60%', tone: 'var(--c-lavender-300)' },
    { label: 'infra', val: '40%', tone: 'var(--c-forest-300)' },
  ];
  return (
    <div style={{ display: 'flex', flexDirection: 'column', gap: 6 }}>
      <div
        style={{
          display: 'flex',
          alignItems: 'baseline',
          gap: 8,
          marginBottom: 4,
        }}
      >
        <div
          style={{
            fontFamily: 'var(--conduction-typography-font-family-code)',
            fontSize: 22,
            fontWeight: 700,
            color: 'var(--c-cobalt-700)',
          }}
        >
          342
        </div>
        <div
          style={{
            fontFamily: 'var(--conduction-typography-font-family-code)',
            fontSize: 9,
            letterSpacing: '0.05em',
            textTransform: 'uppercase',
            color: 'var(--c-cobalt-400)',
          }}
        >
          apps tracked
        </div>
      </div>
      {rows.map((row, i) => (
        <div
          key={i}
          style={{ display: 'flex', alignItems: 'center', gap: 6 }}
        >
          <div
            style={{
              fontFamily: 'var(--conduction-typography-font-family-code)',
              fontSize: 8,
              letterSpacing: '0.05em',
              textTransform: 'uppercase',
              color: 'var(--c-cobalt-500)',
              width: 60,
            }}
          >
            {row.label}
          </div>
          <div
            style={{
              flex: 1,
              height: 5,
              background: 'var(--c-cobalt-100)',
              borderRadius: 1,
            }}
          >
            <div
              style={{
                height: '100%',
                width: row.val,
                background: row.tone,
                borderRadius: 1,
              }}
            />
          </div>
        </div>
      ))}
    </div>
  );
}

function DiscoveryPanel() {
  const rows = [
    { tone: 'var(--c-mint-500)', stage: 'new' },
    { tone: 'var(--c-cobalt-300)', stage: 'updated' },
    { tone: 'var(--c-red-vermillion)', stage: 'removed' },
    { tone: 'var(--c-cobalt-300)', stage: 'updated' },
  ];
  return (
    <div style={{ display: 'flex', flexDirection: 'column', gap: 6 }}>
      {rows.map((row, i) => (
        <div
          key={i}
          style={{
            display: 'flex',
            alignItems: 'center',
            gap: 8,
            padding: '4px 0',
            borderBottom:
              i < rows.length - 1 ? '1px solid var(--c-cobalt-50)' : 'none',
          }}
        >
          <span
            style={{
              width: 10,
              height: 11,
              clipPath: 'var(--hex-pointy-top)',
              background: row.tone,
              flexShrink: 0,
            }}
          />
          <div
            style={{
              flex: 1,
              display: 'flex',
              flexDirection: 'column',
              gap: 2,
            }}
          >
            <div
              style={{
                height: 4,
                width: '70%',
                background: 'var(--c-cobalt-700)',
                borderRadius: 1,
              }}
            />
            <div
              style={{
                height: 3,
                width: '50%',
                background: 'var(--c-cobalt-200)',
                borderRadius: 1,
              }}
            />
          </div>
          <div
            style={{
              fontFamily: 'var(--conduction-typography-font-family-code)',
              fontSize: 8,
              letterSpacing: '0.05em',
              textTransform: 'uppercase',
              color: 'var(--c-cobalt-500)',
            }}
          >
            {row.stage}
          </div>
        </div>
      ))}
    </div>
  );
}

const WIDGETS = [
  {
    title: 'Renewals due',
    desc: 'Licences and contracts coming up. Pre-warning before the auto-renewal date, escalation after.',
    panel: <RenewalsPanel />,
  },
  {
    title: 'Inventory snapshot',
    desc: 'Apps tracked, total seats, deduplicated count. Categories below.',
    panel: <InventoryPanel />,
  },
  {
    title: 'Discovery deltas',
    desc: 'New, updated, removed apps detected by the discovery sync. One click to the change record.',
    panel: <DiscoveryPanel />,
  },
];

export default function Home() {
  return (
    <Layout
      title="SoftwareCatalog"
      description="IT-asset management on Nextcloud. Software inventory, licences, contracts, dependencies. One register, every install."
    >
      <main className="marketing-page">
        <DetailHero
          appId="softwarecatalog"
          background="cobalt"
          status={{ label: 'Stable', color: 'var(--c-mint-500)' }}
          version="v1.1"
          locales="NL · EN"
          title="SoftwareCatalog"
          tagline={TAGLINE}
          primaryCta={{
            label: 'Install from app store',
            href: 'https://apps.nextcloud.com/apps/softwarecatalog',
            tone: 'orange',
          }}
          secondaryCta={{ label: 'Read the docs', href: '/docs/FEATURES' }}
          tertiaryCta={{
            label: 'View on GitHub',
            href: 'https://github.com/ConductionNL/softwarecatalog',
          }}
          iconColor="var(--c-orange-knvb)"
          icon={SOFTWARECATALOG_ICON}
          illustration={<AppMock app="softwarecatalog" />}
        />

        <WidgetShelf
          eyebrow="Widgets we ship"
          title="What runs where, on the IT-lead's home screen."
          lede="Install SoftwareCatalog and these widgets show up on the dashboard for every IT-admin. Renewals first, inventory snapshot next, recent discovery deltas below."
          widgets={WIDGETS}
        />
      </main>
    </Layout>
  );
}
