<!--
  - SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
  - SPDX-License-Identifier: EUPL-1.2
  -->

# APIs

An API records an interface an application offers to other applications: how it is called, its version, where its specification and documentation live, the standards it follows and where it is in its life. The catalogue keeps APIs next to the application that offers them.

Specification: [`openspec/changes/connections-api-catalogue/specs/application-interfaces/spec.md`](https://github.com/ConductionNL/stackiq/blob/development/openspec/changes/connections-api-catalogue/specs/application-interfaces/spec.md).

## Recording an API

Open the page of the application, scroll to **APIs** and choose **Add API**. The new API belongs to that application. Fill in:

- **Name** and a short description of what the API offers.
- **Style**: REST, SOAP, GraphQL, event or message, file, or other.
- **Version**.
- **Specification**: the address of the OpenAPI, WSDL or AsyncAPI document.
- **Documentation**: the address of the documentation for developers.
- **Status**: in development, in use, end of support or withdrawn.

On the API's own page you can add the standard versions it follows; the picker lists the GEMMA elements of type standaardversie, as it does for a connection.

## The APIs list

Open **Applications** in the navigation menu, then **APIs**. The list at `/apis` shows every API you may read, with its application, style, version and status.

- The chips above the list filter on status.
- The filter menu in the table header filters on style and status.
- Click a row to open the API.

You see the APIs of your own organisation's applications, and those that are published, exactly as for the applications themselves.

## An API's page

The page shows the API's data, attached documents, the related application, standards and the connections that call it, and its history. The actions at the top move an API through its lifecycle: release (in development to in use), sunset (in use to end of support) and withdraw (to withdrawn).

## Linking a connection to an API

A connection can name the API it calls. On the connection, choose the API in the **API** field; the picker offers the APIs of the connection's application B.

This page is consume-and-record only: stackiq is not a developer portal, so keys, subscriptions and a try-it console are out of scope.
