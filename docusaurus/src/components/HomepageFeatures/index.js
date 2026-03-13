import React from 'react';
import clsx from 'clsx';
import styles from './styles.module.css';

const FeatureList = [
  {
    title: 'Software Registration',
    description: (
      <>
        Register applications and modules with full metadata. Maintain a single source of truth for your entire software portfolio across your organization.
      </>
    ),
  },
  {
    title: 'Connection Mapping',
    description: (
      <>
        Map connections between applications and modules. Visualize your software landscape and understand system dependencies at a glance.
      </>
    ),
  },
  {
    title: 'Open Data & GEMMA',
    description: (
      <>
        Publish your catalogue as open data with GEMMA-compliant classification. Federated synchronization enables cross-organization data sharing.
      </>
    ),
  },
];

function Feature({title, description}) {
  return (
    <div className={clsx('col col--4')}>
      <div className="text--center padding-horiz--md">
        <h3>{title}</h3>
        <p>{description}</p>
      </div>
    </div>
  );
}

export default function HomepageFeatures() {
  return (
    <section className={styles.features}>
      <div className="container">
        <div className="row">
          {FeatureList.map((props, idx) => (
            <Feature key={idx} {...props} />
          ))}
        </div>
      </div>
    </section>
  );
}
