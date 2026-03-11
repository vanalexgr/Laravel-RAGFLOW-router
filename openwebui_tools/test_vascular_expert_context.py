import os
import sys
import tempfile
import types
import unittest
import zipfile


if "httpx" not in sys.modules:
    httpx = types.ModuleType("httpx")

    class AsyncClient:
        def __init__(self, *args, **kwargs):
            pass

        async def __aenter__(self):
            return self

        async def __aexit__(self, exc_type, exc, tb):
            return False

    class TimeoutException(Exception):
        pass

    class HTTPStatusError(Exception):
        pass

    httpx.AsyncClient = AsyncClient
    httpx.TimeoutException = TimeoutException
    httpx.HTTPStatusError = HTTPStatusError
    sys.modules["httpx"] = httpx


if "pydantic" not in sys.modules:
    pydantic = types.ModuleType("pydantic")

    def Field(default=None, description=""):
        return default

    class BaseModel:
        def __init__(self, **kwargs):
            for name, value in self.__class__.__dict__.items():
                if name.startswith("_"):
                    continue
                if callable(value) or isinstance(value, (staticmethod, classmethod, property)):
                    continue
                setattr(self, name, kwargs.get(name, value))

    pydantic.BaseModel = BaseModel
    pydantic.Field = Field
    sys.modules["pydantic"] = pydantic


from openwebui_tools.vascular_expert import Tools


class VascularExpertContextTests(unittest.TestCase):
    def setUp(self):
        self.tools = Tools()

    def test_definition_query_skips_case_follow_up(self):
        question = "What is the WIfI index?"

        self.assertTrue(self.tools._is_raw_guideline_knowledge_query(question))
        self.assertFalse(self.tools._should_request_case_follow_up(question, []))
        self.assertEqual(("", []), self.tools._assess_context_gaps(question, []))

    def test_threshold_query_skips_case_follow_up(self):
        question = "What is the treatment threshold for aortic aneurysm?"

        self.assertTrue(self.tools._is_raw_guideline_knowledge_query(question))
        self.assertFalse(self.tools._should_request_case_follow_up(question, []))

    def test_population_level_guideline_question_skips_case_follow_up(self):
        question = "In which patients with asymptomatic carotid stenosis is CEA recommended?"

        self.assertTrue(self.tools._is_raw_guideline_knowledge_query(question))
        self.assertFalse(self.tools._should_request_case_follow_up(question, []))

    def test_known_patient_case_uses_targeted_follow_up(self):
        question = "Patient with DVT"

        scenario_id, questions = self.tools._assess_context_gaps(question, [])

        self.assertEqual("dvt_pe", scenario_id)
        self.assertEqual(2, len(questions))
        self.assertIn("Provoking factors", questions[0])

    def test_other_patient_case_uses_generic_follow_up(self):
        question = "My patient with renal artery stenosis and uncontrolled hypertension, what does ESVS recommend?"

        scenario_id, questions = self.tools._assess_context_gaps(question, [])

        self.assertEqual("generic_case", scenario_id)
        self.assertGreaterEqual(len(questions), 2)
        self.assertTrue(any("current anticoagulation or antiplatelet therapy" in q for q in questions))

    def test_detailed_patient_case_still_requests_follow_up(self):
        question = "72-year-old man with asymptomatic 75% carotid stenosis, what does ESVS recommend?"

        scenario_id, questions = self.tools._assess_context_gaps(question, [])

        self.assertEqual("generic_case", scenario_id)
        self.assertGreaterEqual(len(questions), 1)

    def test_case_follow_up_turn_uses_history(self):
        history = ["My patient with aortic mural thrombus after stroke"]

        scenario_id, questions = self.tools._assess_context_gaps("What about anticoagulation?", history)

        self.assertEqual("aortic_thrombus", scenario_id)
        self.assertTrue(any("Thrombus morphology" in q for q in questions))
        self.assertTrue(any("Stroke aetiology workup" in q for q in questions))

    def test_case_thread_surveillance_follow_up_still_requests_context(self):
        history = ["My patient with renal artery stenosis and uncontrolled hypertension"]

        scenario_id, questions = self.tools._assess_context_gaps("What about surveillance?", history)

        self.assertEqual("generic_case", scenario_id)
        self.assertGreaterEqual(len(questions), 2)

    def test_no_dvt_case_falls_back_to_generic_follow_up(self):
        question = (
            "Patient with tumor compressing the brachial vein, acute swelling, "
            "no DVT, currently on LMWH prophylaxis — what does ESVS recommend regarding anticoagulation?"
        )

        scenario_id, questions = self.tools._assess_context_gaps(question, [])

        self.assertEqual("generic_case", scenario_id)
        self.assertGreaterEqual(len(questions), 1)

    def test_broad_carotid_management_question_prunes_antithrombotic_companion(self):
        guidelines = self.tools._filter_requested_guidelines(
            ["carotid_vertebral", "antithrombotic_therapy"],
            "Patient following major stroke due to carotid stenosis. He hasn't yet mobilised. What is the best management?",
        )

        self.assertEqual(["carotid_vertebral"], guidelines)

    def test_explicit_anticoagulation_question_keeps_antithrombotic_companion(self):
        guidelines = self.tools._filter_requested_guidelines(
            ["carotid_vertebral", "antithrombotic_therapy"],
            "Stroke patient needs urgent carotid plan but also has acute proximal DVT on ultrasound. How to balance anticoagulation needs with CEA timing?",
        )

        self.assertEqual(["carotid_vertebral", "antithrombotic_therapy"], guidelines)

    def test_major_stroke_carotid_case_enables_severity_scope(self):
        self.assertTrue(
            self.tools._needs_stroke_severity_scope(
                "Patient following major stroke due to carotid stenosis. He hasn't yet mobilised. What is the best management?",
                ["carotid_vertebral"],
            )
        )

    def test_minor_stroke_carotid_case_does_not_enable_severity_scope(self):
        self.assertFalse(
            self.tools._needs_stroke_severity_scope(
                "63F had TIA yesterday with symptomatic carotid stenosis 70% (NASCET) — recommended timing of CEA vs CAS?",
                ["carotid_vertebral"],
            )
        )

    def test_context_request_instructs_model_to_synthesize_complete_case(self):
        rendered = self.tools._format_context_request(
            ["**Clinical scenario**: What is the exact diagnosis?"],
            "What about anticoagulation?",
            ["My patient with aortic mural thrombus after stroke"],
            "aortic_thrombus",
        )

        self.assertIn("Known case context:", rendered)
        self.assertIn("Current user message: What about anticoagulation?", rendered)
        self.assertIn("Earlier case context: My patient with aortic mural thrombus after stroke", rendered)
        self.assertIn("Synthesize ONE standalone clinical scenario", rendered)
        self.assertIn("call `consult_vascular_guidelines` again using that synthesized standalone scenario", rendered)
        self.assertIn("Do NOT say the scenario is or is not addressed by the guidelines.", rendered)
        self.assertIn("Do NOT mention evidence gaps", rendered)
        self.assertIn("REPLY TEMPLATE TO SEND TO THE USER:", rendered)
        self.assertIn("To answer this case accurately, I need a few more details:", rendered)
        self.assertIn("END OF REQUIRED USER-FACING REPLY", rendered)

    def test_generic_case_prompt_avoids_old_generic_bucket_labels(self):
        scenario_id, questions = self.tools._assess_context_gaps(
            "Patient with tumor compressing the brachial vein. Should he take anticoagulation?",
            [],
        )
        rendered = self.tools._format_context_request(
            questions,
            "Patient with tumor compressing the brachial vein. Should he take anticoagulation?",
            [],
            scenario_id,
        )

        self.assertEqual("generic_case", scenario_id)
        self.assertIn("Base each question on the anatomy, pathology, and treatment decision", rendered)
        self.assertIn("Ask what imaging or objective findings are available in this case", rendered)
        self.assertNotIn("WIfI/Rutherford", rendered)
        self.assertNotIn("Key severity or imaging details", rendered)
        self.assertNotIn("Management modifiers", rendered)

    def test_uploaded_text_attachment_is_added_to_effective_question(self):
        with tempfile.NamedTemporaryFile("w", suffix=".txt", delete=False) as handle:
            handle.write(
                "68-year-old man with symptomatic 6.2 cm infrarenal AAA after EVAR, "
                "currently on aspirin, no active bleeding."
            )
            path = handle.name
        self.addCleanup(lambda: os.path.exists(path) and os.remove(path))

        effective_question, history, attachment = self.tools._prepare_consult_inputs(
            "Was this patient managed as per ESVS guidance?",
            [
                {
                    "role": "user",
                    "content": "Was this patient managed as per ESVS guidance?",
                    "files": [{"name": "case.txt", "file": {"path": path}}],
                }
            ],
            standalone=False,
        )

        self.assertIn("Attached case document text:", effective_question)
        self.assertIn("6.2 cm infrarenal AAA", effective_question)
        self.assertEqual(["Was this patient managed as per ESVS guidance?"], history)
        self.assertIn("currently on aspirin", attachment)

    def test_uploaded_docx_attachment_is_added_to_effective_question(self):
        with tempfile.NamedTemporaryFile("wb", suffix=".docx", delete=False) as handle:
            path = handle.name
        self.addCleanup(lambda: os.path.exists(path) and os.remove(path))

        with zipfile.ZipFile(path, "w") as archive:
            archive.writestr(
                "word/document.xml",
                (
                    "<w:document xmlns:w='http://schemas.openxmlformats.org/wordprocessingml/2006/main'>"
                    "<w:body><w:p><w:r><w:t>Acute upper limb swelling from tumor compression "
                    "of the brachial vein, no DVT on duplex, on LMWH prophylaxis.</w:t></w:r></w:p>"
                    "</w:body></w:document>"
                ),
            )

        effective_question, history, attachment = self.tools._prepare_consult_inputs(
            "Was this managed as per ESVS guidance?",
            [
                {
                    "role": "user",
                    "content": "Was this managed as per ESVS guidance?",
                    "files": [
                        {
                            "name": "case.docx",
                            "file": {
                                "path": path,
                                "filename": "case.docx",
                                "meta": {
                                    "content_type": "application/vnd.openxmlformats-officedocument.wordprocessingml.document"
                                },
                            },
                        }
                    ],
                }
            ],
            standalone=False,
        )

        self.assertIn("Attached case document text:", effective_question)
        self.assertIn("tumor compression of the brachial vein", effective_question)
        self.assertEqual(["Was this managed as per ESVS guidance?"], history)
        self.assertIn("no DVT on duplex", attachment)

    def test_top_level_files_are_added_to_effective_question(self):
        with tempfile.NamedTemporaryFile("w", suffix=".txt", delete=False) as handle:
            handle.write("Symptomatic 8 cm pararenal thoracoabdominal aneurysm with imminent rupture signs.")
            path = handle.name
        self.addCleanup(lambda: os.path.exists(path) and os.remove(path))

        effective_question, history, attachment = self.tools._prepare_consult_inputs(
            "Was this patient managed as per ESVS guidance?",
            [],
            standalone=False,
            files=[{"name": "case.txt", "file": {"path": path}}],
            metadata={"files": []},
        )

        self.assertIn("Attached case document text:", effective_question)
        self.assertIn("imminent rupture signs", effective_question)
        self.assertEqual([], history)
        self.assertIn("pararenal thoracoabdominal aneurysm", attachment)

    def test_substantial_attachment_context_still_uses_gate(self):
        attachment = (
            "This patient has a long attached discharge summary with the vascular diagnosis, "
            "operative details, postoperative imaging, medications, and follow-up plan. "
            "It also includes the index presentation, relevant comorbidities, procedural steps, "
            "postoperative duplex findings, anticoagulation strategy, and discharge instructions "
            "needed to judge whether management aligned with the guideline recommendations."
        )

        self.assertTrue(self.tools._has_substantial_attachment_context(attachment, []))
        self.assertTrue(
            self.tools._has_substantial_attachment_context(
                "",
                [f"[Attached case document]\n{attachment}"],
            )
        )
        self.assertFalse(self.tools._has_substantial_attachment_context("brief note", []))

        scenario_id, questions = self.tools._assess_context_gaps(
            "Was this patient managed as per ESVS guidance?",
            [f"[Attached case document]\n{attachment}"],
        )
        self.assertEqual("generic_case", scenario_id)
        self.assertGreaterEqual(len(questions), 1)

    def test_any_uploaded_document_in_thread_still_triggers_follow_up_gate(self):
        messages = [
            {
                "role": "user",
                "content": "Was this patient managed as per ESVS guidance?",
                "files": [{"name": "case.docx", "file": {"path": "/tmp/fake.docx"}}],
            },
            {
                "role": "user",
                "content": "post-amputation stump infection. bypass patent. no bleeding risk",
            },
        ]

        self.assertTrue(self.tools._thread_has_uploaded_document(messages))
        effective_question, history, attachment = self.tools._prepare_consult_inputs(
            messages[-1]["content"],
            messages,
            standalone=False,
        )
        self.assertTrue(self.tools._should_request_case_follow_up(effective_question, history))
        scenario_id, questions = self.tools._assess_context_gaps(effective_question, history)
        self.assertEqual("graft_infection", scenario_id)
        self.assertGreaterEqual(len(questions), 1)
        self.assertTrue(
            self.tools._thread_has_uploaded_document(
                [],
                files=[{"name": "case.docx"}],
                metadata={},
            )
        )

    def test_prior_gate_same_case_does_not_reopen_gate(self):
        messages = [
            {
                "role": "user",
                "content": "Patient following major stroke due to carotid stenosis. He hasn't yet mobilised. What is the best management?",
            },
            {
                "role": "assistant",
                "content": "To answer this case accurately, I need a few more details:\n1. Was the stroke disabling?\n2. What imaging is available?",
            },
            {
                "role": "user",
                "content": "Disabling stroke, mRS 4, no CTA yet.",
            },
        ]

        self.assertFalse(
            self.tools._should_open_case_gate(
                messages[-1]["content"],
                "",
                messages,
            )
        )

    def test_prior_gate_new_case_reopens_gate(self):
        messages = [
            {
                "role": "user",
                "content": "Patient following major stroke due to carotid stenosis. He hasn't yet mobilised. What is the best management?",
            },
            {
                "role": "assistant",
                "content": "To answer this case accurately, I need a few more details:\n1. Was the stroke disabling?\n2. What imaging is available?",
            },
            {
                "role": "user",
                "content": "Another patient with 6.2 cm infrarenal AAA and iliac aneurysm. What does ESVS recommend?",
            },
        ]

        self.assertTrue(
            self.tools._should_open_case_gate(
                messages[-1]["content"],
                "",
                messages,
            )
        )

    def test_conversation_state_tracks_case_context_for_same_case_follow_up(self):
        messages = [
            {
                "role": "user",
                "content": (
                    "For a patient who has suffered a major, disabling stroke "
                    "(such as one preventing mobilisation, corresponding to a modified Rankin score > 3) "
                    "what is the recommended management? Should we defer any carotid interventions "
                    "(carotid endarterectomy or stenting) until neurological improvement has occurred?"
                ),
            },
            {
                "role": "assistant",
                "content": (
                    "To answer this case accurately, I need a few more details:\n"
                    "1. Has the patient's carotid stenosis been classified as symptomatic?\n"
                    "2. What is the degree of carotid stenosis in this case?"
                ),
            },
            {
                "role": "user",
                "content": "1. Yes.\n2. 90%",
            },
            {
                "role": "assistant",
                "content": (
                    "🩺 Clinical Synthesis\n"
                    "[6] Rec 46 (Class I, Level C) — Defer carotid intervention after disabling stroke."
                ),
            },
            {
                "role": "user",
                "content": "What is the best intervention for this case? CAS or CEA?",
            },
        ]

        state = self.tools._build_conversation_state(
            messages[-1]["content"],
            messages,
            ["carotid_vertebral"],
        )

        self.assertEqual(["carotid_vertebral"], state["current_guidelines"])
        self.assertEqual("Carotid/Vertebral", state["current_topic"])
        self.assertTrue(any("symptomatic" in item.lower() and "yes" in item.lower() for item in state["clarified_facts"]))
        self.assertTrue(any("90%" in item for item in state["clarified_facts"]))
        self.assertIn("Rec 46", state["previous_recommendations"])
        self.assertIn("disabling stroke", state["patient_problem_context"].lower())

    def test_same_case_follow_up_is_rewritten_into_standalone_retrieval_query(self):
        messages = [
            {
                "role": "user",
                "content": (
                    "For a patient who has suffered a major, disabling stroke "
                    "(such as one preventing mobilisation, corresponding to a modified Rankin score > 3) "
                    "what is the recommended management? Should we defer any carotid interventions "
                    "(carotid endarterectomy or stenting) until neurological improvement has occurred?"
                ),
            },
            {
                "role": "assistant",
                "content": (
                    "To answer this case accurately, I need a few more details:\n"
                    "1. Has the patient's carotid stenosis been classified as symptomatic?\n"
                    "2. What is the degree of carotid stenosis in this case?"
                ),
            },
            {
                "role": "user",
                "content": "1. Yes.\n2. 90%",
            },
            {
                "role": "assistant",
                "content": (
                    "🩺 Clinical Synthesis\n"
                    "[6] Rec 46 (Class I, Level C) — Defer carotid intervention after disabling stroke."
                ),
            },
            {
                "role": "user",
                "content": "What is the best intervention for this case? CAS or CEA?",
            },
        ]

        state = self.tools._build_conversation_state(
            messages[-1]["content"],
            messages,
            ["carotid_vertebral"],
        )
        rewritten, history, changed = self.tools._prepare_retrieval_query(
            messages[-1]["content"],
            messages[-1]["content"],
            state,
            standalone=False,
        )

        self.assertTrue(changed)
        self.assertEqual([], history)
        self.assertIn("Current guideline(s): Carotid/Vertebral.", rewritten)
        self.assertIn("90%", rewritten)
        self.assertIn("carotid artery stenting (CAS)", rewritten)
        self.assertIn("carotid endarterectomy (CEA)", rewritten)
        self.assertNotEqual(messages[-1]["content"], rewritten)

    def test_clarification_answer_turn_is_rewritten_as_clarified_case_query(self):
        messages = [
            {
                "role": "user",
                "content": (
                    "For a patient who has suffered a major, disabling stroke "
                    "(such as one preventing mobilisation, corresponding to a modified Rankin score > 3) "
                    "what is the recommended management? Should we defer any carotid interventions "
                    "(carotid endarterectomy or stenting) until neurological improvement has occurred?"
                ),
            },
            {
                "role": "assistant",
                "content": (
                    "To answer this case accurately, I need a few more details:\n"
                    "1. Has the patient's carotid stenosis been classified as symptomatic?\n"
                    "2. What is the degree of carotid stenosis in this case?"
                ),
            },
            {
                "role": "user",
                "content": "1. Yes.\n2. 90%",
            },
        ]

        state = self.tools._build_conversation_state(
            messages[-1]["content"],
            messages,
            ["carotid_vertebral"],
        )
        rewritten, history, changed = self.tools._prepare_retrieval_query(
            messages[-1]["content"],
            messages[-1]["content"],
            state,
            standalone=False,
        )

        self.assertTrue(changed)
        self.assertEqual([], history)
        self.assertIn("What does ESVS recommend for this clarified case?", rewritten)
        self.assertIn("symptomatic", rewritten.lower())
        self.assertIn("90%", rewritten)
        self.assertNotIn("1. Yes. 2. 90%", rewritten)


if __name__ == "__main__":
    unittest.main()
