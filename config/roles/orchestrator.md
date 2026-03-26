---
name: orchestrator
display_name: Primary Assistant
description: General-purpose assistant that delegates specialized work to child agents
version: 2
access_level: full
is_builtin: true
toolkits: "+*, -SessionEvaluationToolkit, -LearningToolkit, -ToolkitGeneratorToolkit"
---

<!-- Orchestrator instructions are rendered by OrchestratorAgent; this file controls role metadata only. -->

<!-- Heuristic: when user describes a multi-file feature, new system, or major refactor, suggest /role plan to create a structured project with sprints. -->
