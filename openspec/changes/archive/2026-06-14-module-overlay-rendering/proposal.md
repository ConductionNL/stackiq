# Proposal: module-overlay-rendering

## Summary
Render module information as overlay nodes on GEMMA architecture views using JointJS, showing which software modules are associated with architectural components.

## Motivation
GEMMA views currently show static architecture components. Overlaying actual software module assignments provides actionable insight into which software serves which architectural function.

## Scope
- JointJS overlay node rendering for modules
- Parent-child node positioning with topological sort
- Color coding and interactive tooltips
- Paper freeze/unfreeze pattern for performance
