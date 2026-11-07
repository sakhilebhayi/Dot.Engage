# Dot.Engage: complete product transformation (R-39)

Owner brief, 2026-11-07, every requirement kept, wording tightened. The working order that came with it: build every feature and the design without stopping, use the Awwwards skill only for UI/UX and Laravel Boost only for the backend, deploy, and stop only after the deployment is done.

## 1. The vision

Dot.Engage must become a premium business engagement platform that allows businesses, clients, suppliers, partners, contractors and other stakeholders to meet, discuss agreements, negotiate contracts, approve terms and sign documents digitally, all within one seamless environment.

Three immediate problems:

1. The UI/UX contains too much AI slop. The design lacks the sophistication, consistency and professional quality expected from a serious business platform.
2. Digital signing is unreliable. It needs a proper technical investigation and improvement.
3. Video conferencing is unreliable. Meetings and video communication are not delivering the dependable experience required for real business negotiations and contract discussions.

Do not approach this as a simple visual refresh. Examine the entire platform, identify the underlying problems, fix the technical weaknesses and transform Dot.Engage into a dependable business tool that people genuinely enjoy using.

Businesses should be able to engage, negotiate, agree and sign without unnecessary emails, document exchanges, disconnected meeting tools or complicated processes. A business should be able to invite another party, arrange a meeting, discuss a contract, make amendments, obtain approval and complete the signing process during the same engagement, and it should feel natural, professional, secure and effortless.

## 2. Tooling

Awwwards skill exclusively for UI/UX and visual design (identity, layout, typography, spacing, hierarchy, navigation, interaction, journeys, responsive behaviour, microinteractions, animation, consistency, accessibility, overall experience). Laravel Boost exclusively for backend investigation, engineering and optimisation (architecture, routes, models, services, authentication, signing, video infrastructure, APIs, jobs, events, real-time, storage, error handling, performance, security). Preserve the existing stack; introduce new infrastructure only when a documented technical requirement justifies it.

## 3. Audit first

Classify every module (fully functional; functional but unreliable; partially implemented; visually implemented but not connected; broken; missing; technically unsuitable for production). Investigate digital signing (preparation, invitations, links, identity verification, capture, order, multiple signatories, completion, persistence, audit, signed document generation, notifications, failure recovery), video conferencing (creation, invitations, joining, permissions, devices, participants, quality, screen sharing, controls, connectivity, reconnection, termination, records), contract engagement (creation, upload, preview, sharing, discussions, amendments, versions, approvals, signing workflows, completed storage) and the general platform (dashboard, navigation, workspaces, notifications, search, documents, settings, permissions, responsive, error/loading/empty states). Identify root causes. Produce a findings report internally before implementing. Do not remove functionality simply because it is difficult to repair.

## 4. Redesign

Eliminate the AI-generated appearance. It should feel like a sophisticated international business application: trust, professionalism, confidence, simplicity, precision, reliability, business readiness. No generic AI layouts, unnecessary gradients or decoration, excessive glassmorphism, repetitive cards, wasted whitespace, oversized headings, random icons, decorative charts or meaningless statistics, robotic copy, or generic SaaS dashboards. Every element has a purpose. Design the actual workflows end to end: open Dot.Engage, create or open an engagement, invite the other party, share a contract, start a discussion or meeting, negotiate, make or approve amendments, initiate signing, all parties sign, the completed agreement is generated and securely stored. The user should never feel lost; provide progress indicators, meaningful status and intuitive next actions.

## 5. Engagements as the central concept

An engagement is a business interaction between two or more parties about an agreement, contract, proposal or commercial arrangement (supplier agreements, SLAs, employment contracts, NDAs, partnership, purchase, consultancy, client proposals, commercial contracts, project agreements, MOUs, general). Each has its own workspace: title, reference, participating businesses, individual participants, owner, contract documents, meeting schedule, video access, discussion history, comments, proposed amendments, approval status, signing status, document versions, activity history, notifications, final signed documents. It communicates the current state immediately.

Lifecycle: draft, invitation sent, awaiting participants, discussion scheduled, in discussion, negotiation, awaiting amendments, awaiting approval, ready for signing, partially signed, fully signed, completed, declined, cancelled, expired. Statuses come from actual workflow events and backend state, not manually displayed labels.

## 6. Digital signing

Reliable enough for real commercial agreements. Investigate the whole architecture. Preparation: upload contracts, select templates, preview, add signatories, define order, place signature, initials, date, text and acknowledgement fields, assign fields to participants; placement accurate across formats and page sizes. Signing experience: secure invitation, open, review the complete document, understand what is being signed, complete fields, apply signature, confirm intent, receive confirmation, access the completed document. Methods: drawn, typed, uploaded image, approved provider integrations. A captured image alone is never sufficient evidence of a legally valid signature in every jurisdiction. Multiple signatories: two-party, multi-party, sequential, parallel, assigned fields, deadlines, reminders, declining, reassignment, cancellation, expiration; never fully signed until every required condition is truly met. Evidence per completed signing: document id and version, cryptographic hash, signer identity and verification method, contact, timestamps, actions, consent and intent, authentication events, completion status, audit events, integrity evidence; secure timestamps and appropriate cryptography; completed documents protected against modification; a change needs a new version and a new signing process, never a silent edit. Design with ECTA (South Africa), eIDAS, US federal and state law and other jurisdictions in mind; do not claim every method is legally equivalent; distinguish basic from advanced or qualified workflows; use properly authorised providers where stronger assurance is required.

## 7. Video conferencing

Investigate why the current one performs poorly; do not assume the integrated technology is appropriate. Capabilities: create, schedule, instant, invite, secure links, waiting room, join and leave, camera and microphone control, speaker and camera selection, screen sharing, participant list, host controls (mute, remove, lock), connection status and quality, reconnection, termination. Investigate WebRTC configuration, signalling, ICE, STUN/TURN, NAT traversal, firewalls, media transport, audio routing, rendering, permissions, devices, mobile, synchronisation, recovery, server resource use. Assess whether an SFU is needed; do not introduce infrastructure without understanding deployment. The interface shows who is attending, who is speaking, connection quality, mic and camera state, screen sharing, recording status; clear feedback when someone cannot connect or denied permissions; never show a participant as connected when they have not joined. Meetings are connected to the engagement: open the contract, review sections, share screen, discuss terms, record agreed changes, view outstanding issues, confirm a section was discussed, move to approval and signing; a meeting-side document view; discussions never silently alter legal documents. Recording and transcription are optional: consent, visible status, security, retention, privacy law, identifiable generated outputs, review; never record secretly.

## 8. Contract negotiation

View, navigate pages, highlight, comment on clauses, ask questions, suggest changes, reply, resolve threads, mark issues addressed, track outstanding items; comments tied to the right version and page or text. Controlled amendments: proposed text, clause replacement, added and removed clauses, explanations, author, review status, acceptance or rejection, revision history, version comparison. Agreement readiness checklist before signing (participants invited, documents available, comments resolved or acknowledged, amendments reviewed, approvals complete, signatories assigned, fields placed, order configured); do not initiate signing when conditions are unmet.

## 9. Unified communication

Engagement discussions, document comments, mentions, notifications, meeting invitations and updates, signing, amendment and approval notifications. Not another disconnected messaging app. Email notifications carry secure links and do not expose sensitive contract information unnecessarily. A chronological activity history.

## 10. Documents and storage

Secure uploads, PDF preview, other previews, versioning, comparison, secure downloads, access controls, final signed document generation, evidence storage, retention. Use Dot.Files as the storage layer where it exists and its APIs support it; no unnecessary duplicate storage. Every participant sees only documents they are authorised to view. Completed agreements are easy to find, retrieve and verify.

## 11. Identity, access and security

InfoDot remains the central identity and authentication gateway; no independent identity system. Roles: engagement owner, business administrator, contract manager, legal reviewer, approver, signatory, meeting host, participant, external invited party. Permissions enforced server-side. An external party only receives access to the specific engagement and documents intended for them. Protect signing links, meeting links, documents, participant information, discussions, audit records and recordings. Secure tokens, expiration, revocation, access validation. Do not rely on hiding interface elements.

## 12. Backend reliability

Signing: reliable document processing, field persistence, atomic operations, correct signer assignment, idempotency, secure generation, hashing, audit persistence, failure recovery, concurrency. Video: lifecycle, secure rooms, participant authentication, signalling reliability, real-time delivery, connection state, media integration, cleanup, expiry, recovery. Workflows: state transitions, transactions, permissions, events, notifications, jobs, retries, idempotency, audit. Performance: indexing, queries, caching, queues, file processing, real-time efficiency, resources. Every critical workflow handles network interruptions, duplicate requests, refreshes, expired sessions and invitations, failed document processing, concurrent signing, failed notifications, interrupted meetings and server restarts. A failed operation never leaves the engagement inconsistent. Success is never reported before it has actually succeeded.

## 13. External participants

Invite someone without a Dot account: receive an invitation, understand who invited them and why, verify identity by the configured method, access the engagement, join a meeting, review the agreement, discuss, approve or propose amendments, sign, receive the completed agreement. Avoid unnecessary registration; do not weaken verification merely for convenience.

## 14. Notifications and management

Notify on invitation, meeting scheduled or changed, document upload, amendment, comment, approval needed, signature requested, signed, all signed, approaching expiry. A dashboard of active engagements, awaiting my action, upcoming meetings, contracts awaiting signature, outstanding amendments, recently completed, expired or cancelled. Every status and count from actual records.

## 15. Auditability

An append-only or tamper-resistant audit history of engagement creation, invitations, acceptance, uploads, revisions, comments and amendments, approvals, signing requests and events, signature completion, document generation, meeting creation and participation, completion. Actor, event, timestamp and affected resource. Restricted access. Ordinary users cannot rewrite or delete signing evidence. Design for external trusted timestamping, certification or archival where required.

## 16. Performance, compatibility, accessibility

Desktop, laptop, tablet, mobile, screen sizes, supported browsers, camera and microphone devices, network conditions; external signing and mobile conferencing in particular. Immediate button feedback, meaningful loading states, errors that explain what happened and what to do, recovery from interruptions, keyboard navigation, readable text, visible focus, reduced motion. Never sacrifice reliability for animation.

## 17. Mandatory end-to-end testing

Signing: create an engagement, upload, preview, add signatories, place and assign fields, sequential order, send, open as an external participant, complete, verify persistence, signed document, hash and audit evidence, all signed, final status, duplicate signing attempt, unauthorised access, interruption and recovery, modify the original and verify the signed version is unchanged, expired or revoked links. Video: instant and scheduled meetings, internal and external invitees, joining, camera and microphone, devices, screen share, multiple participants, disconnection, reconnection, poor network, ending, meeting state and participant records, expired links, mobile. Contract engagement: create, invite another business, share, discuss, comment, propose, review, accept or reject, revised version, approvals, sign, complete, retrieve, audit history. Security: tenant isolation, external restrictions, signing token security, meeting access, document permissions, server-side authorisation, sensitive data, audit integrity, rate limiting, secure file handling. Add tests based on the actual implementation.

## 18. Roadmap

Phase 1 audit and diagnosis. Phase 2 UI/UX transformation. Phase 3 digital signing reliability. Phase 4 video conferencing reliability. Phase 5 unified engagement workflows. Phase 6 production readiness. Prioritise fixing the existing signing and conferencing problems before introducing unnecessary new features.

## 19. Definition of done

- The platform no longer looks like generic AI-generated software, and the whole experience feels premium and coherent.
- Businesses can create and manage engagements; external participants can join securely.
- Contracts can be reviewed and discussed; amendments are controlled and traceable.
- Meetings work reliably; participants communicate without leaving the engagement.
- Digital signing works reliably, with multiple signatories handled correctly.
- Completed documents are securely generated and stored; signing evidence is preserved.
- Permissions are enforced server-side; InfoDot remains the central identity gateway.
- Critical workflows recover safely from failures.
- Mobile and desktop experiences work properly; real end-to-end tests pass.
- No fake integrations or simulated functionality remain.

## 20. Final direction

Not a generic communication platform, video meeting application or document-signing tool; its value is the combination: Engage, Discuss, Negotiate, Approve, Sign, Complete. Prioritise reliability, trust, legal evidence, security and ease of use over feature quantity. Dot.Engage: Meet. Agree. Sign. Move Business Forward.
