<x-plain-layout title="How signing works">
    <div class="page-head"><div><h1>How signing works</h1>
        <p class="lede">What Dot.Engage records when people sign, what it can and cannot promise, and where you need something stronger. This page explains the product. It is not legal advice.</p></div></div>

    <article class="read">
        <h2>What a signature here is</h2>
        <p>Dot.Engage provides a <b>basic electronic signature</b>. A person reviews the whole document, completes their fields, agrees to sign electronically and draws, types or uploads their signature. The signature is placed on the page where the sender put the field.</p>

        <h2>How we know who signed</h2>
        <ul>
            <li>People inside your business sign in through InfoDot. That sign-in is the identity check.</li>
            <li>People outside your business get an emailed link and must enter a six-digit code sent to the same address. The code works once and expires in minutes.</li>
            <li>We record the address, the time, the network address and the browser or device, and the exact sentence each person agreed to.</li>
        </ul>
        <p>An email code proves access to an inbox. It does not prove a legal identity, and it is weaker than a signature backed by a government identity document.</p>

        <h2>What is stored when everyone has signed</h2>
        <ul>
            <li>The signed agreement: the original with the signatures drawn on, and a certificate of completion appended. The original content is untouched underneath, so its SHA-256 fingerprint is a verifiable prefix.</li>
            <li>An evidence record listing the document fingerprint, every signer, consent, time and device, and the fingerprint of the final file.</li>
            <li>A seal: Dot.Engage's own signature over that record. It shows the record has not changed since it was written.</li>
            <li>A history of the whole engagement in which every entry is linked to the one before it.</li>
        </ul>
        <p>Anyone holding the signed PDF can upload it on the <a href="{{ route('verify.show') }}">check page</a>. It says whether the file is a signed copy we completed and unchanged. It shows a reference, a date and a count, never names or contents.</p>

        <h2>A signed agreement is never edited</h2>
        <p>Once a signing request is sent the document is locked. To change anything you cancel the request, create a new version and start again. Earlier versions, including signed ones, are kept as they were.</p>

        <h2>Where a basic signature may not be enough</h2>
        <p>The law differs from country to country and by type of document. In broad terms:</p>
        <ul>
            <li><b>South Africa.</b> The Electronic Communications and Transactions Act recognises electronic signatures and does not deny a signature legal effect only because it is electronic. Where the law specifically requires an <em>advanced</em> electronic signature, that means one supported by an accredited authentication service, which Dot.Engage is not.</li>
            <li><b>European Union.</b> eIDAS distinguishes simple, advanced and qualified signatures. A qualified signature has the same effect as a handwritten one. Dot.Engage provides the simple level only.</li>
            <li><b>United States.</b> Federal and state law generally accept electronic signatures on commercial contracts where the parties intend to sign electronically and the record is kept.</li>
            <li>Some documents, such as wills and certain transfers of land, often cannot be signed electronically at all.</li>
        </ul>
        <p>When stronger identity assurance or a certificate is required, use an accredited trust service provider. Dot.Engage will not claim to supply one.</p>

        <h2>Timestamps</h2>
        <p>Each record carries the time Dot.Engage's server saw it. A deployment can also request a timestamp from an independent authority (RFC 3161); only the document's fingerprint is sent, never its content. The agreement's page says whether that was done.</p>

        <h2>Meetings</h2>
        <p>Video calls connect the browsers of the people in them directly and are not recorded or transcribed by Dot.Engage. A person who is not let in by the host never sees or hears the meeting.</p>

        <p class="note">If your agreement matters to you, have a lawyer confirm that this signature level is acceptable for it in the countries involved.</p>
    </article>
</x-plain-layout>
