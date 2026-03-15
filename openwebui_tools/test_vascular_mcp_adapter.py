import sys
import types
import unittest


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


from openwebui_tools.vascular_mcp_adapter import Tools


class VascularMcpAdapterHeuristicTests(unittest.TestCase):
    def setUp(self):
        self.tools = Tools()
        self.session = {
            "pre_result": {
                "provisional_diagnosis": "Symptomatic high-grade carotid artery stenosis following transient ischemic attack",
                "retrieval_query": "symptomatic carotid stenosis transient ischemic attack",
            }
        }

    def test_explicit_new_case_resets_session(self):
        self.assertTrue(
            self.tools._should_treat_as_new_query(
                "For a different patient with a 5.8 cm AAA, what do the guidelines recommend?",
                self.session,
            )
        )

    def test_fresh_case_intro_resets_session(self):
        self.assertTrue(
            self.tools._should_treat_as_new_query(
                "72-year-old man with symptomatic carotid stenosis after TIA, what next?",
                self.session,
            )
        )

    def test_answer_only_turn_stays_in_same_case(self):
        self.assertFalse(self.tools._should_treat_as_new_query("yes proceed", self.session))

    def test_raw_guideline_question_starts_new_query(self):
        self.assertTrue(
            self.tools._should_treat_as_new_query(
                "What is the Rutherford classification for acute limb ischaemia?",
                self.session,
            )
        )

    def test_same_case_diagnosis_correction_reaches_backend_change_detection(self):
        self.assertFalse(
            self.tools._should_treat_as_new_query(
                "Actually this is a new diagnosis of type B aortic dissection with chest pain.",
                self.session,
            )
        )

    def test_pending_gate_detection_tolerates_status_prefix_and_disclaimer(self):
        self.assertTrue(
            self.tools._is_pending_gate_message(
                "Interpreting the clinical question before retrieval...\n"
                "-> Understanding: Acute non-A non-B aortic dissection\n"
                "-> Searching: Thoracic Aorta, Aortic Arch\n"
                "-> Query terms: non-A non-B dissection, thoracic aorta\n"
                "Reply to confirm, or add details to refine the search.\n"
                "The provided ESVS guideline context does not explicitly address this scenario."
            )
        )

    def test_pending_gate_detection_accepts_icon_structured_gate(self):
        self.assertTrue(
            self.tools._is_pending_gate_message(
                "Clinical Query Checkpoint\n\n"
                "🩺 Understanding\n"
                "Acute non-A non-B aortic dissection just above the left subclavian artery with carotid extension.\n\n"
                "📚 Searching\n"
                "Thoracic Aorta, Aortic Arch, Carotid & Vertebral\n\n"
                "🏷️ Query Terms\n"
                "non-A non-B dissection, thoracic aorta, carotid extension\n\n"
                "❓ To Sharpen\n"
                "- When did the stroke occur?\n"
                "- What is the patient's neurological status now?\n\n"
                "✅ Reply to confirm, or add details to refine the search."
            )
        )

    def test_answer_only_turn_accepts_brief_multi_clause_clarification_reply(self):
        self.assertTrue(self.tools._is_answer_only_turn("10 days ago. minor stroke. no"))

    def test_pending_gate_can_be_reused_for_rewritten_follow_up_after_brief_answers(self):
        messages = [
            {"role": "user", "content": "My patient has a dissection just above the left subclavian and also dissected the carotid with thrombus and stroke."},
            {
                "role": "assistant",
                "content": (
                    "Interpreting the clinical question before retrieval...\n"
                    "-> Understanding: Acute non-A non-B aortic dissection just above the left subclavian artery with carotid extension, carotid thrombus, and ischemic stroke\n"
                    "-> Searching: Thoracic Aorta, Aortic Arch, Carotid & Vertebral\n"
                    "-> Query terms: non-A non-B dissection, thoracic aorta, left subclavian, carotid extension, carotid thrombus, ischemic stroke\n"
                    "-> To sharpen: When did the stroke occur? / What is the patient's neurological status now? / Is there evidence of ongoing cerebral malperfusion?\n"
                    "Reply to confirm, or add details to refine the search.\n"
                    "The provided ESVS guideline context does not explicitly address this scenario."
                ),
            },
            {"role": "user", "content": "10 days ago. minor stroke. no"},
        ]
        question = (
            "Patient with acute non-A non-B aortic dissection just above the left subclavian artery, "
            "with carotid artery extension containing thrombus and ischemic stroke 10 days ago with minor deficits — "
            "guidance on whether to operate."
        )

        self.assertTrue(self.tools._can_reuse_pending_gate(question, messages))


class VascularMcpAdapterRecoveryTests(unittest.IsolatedAsyncioTestCase):
    async def test_missing_session_recovers_prior_gate_from_history(self):
        class RecoveryTools(Tools):
            def __init__(self):
                super().__init__()
                self.calls = []

            async def _emit_status(self, emitter, description: str, done: bool = False):
                self.calls.append(("status", description, done))

            async def _call_pre_retrieval(self, question: str, history: list, guidelines: list) -> dict:
                self.calls.append(("pre", question, tuple(history), tuple(guidelines)))
                return {
                    "phase": "awaiting_confirmation",
                    "confirmation_message": "gate message",
                    "pre_retrieval_result": {
                        "proceed": True,
                        "soft_warn": True,
                        "clarification_questions": [
                            "What is the extent and location along the saphenous vein?"
                        ],
                        "provisional_diagnosis": "Patient with thrombosis involving the saphenous vein of the lower limb.",
                        "guidelines": ["venous_thrombosis", "chronic_venous_disease"],
                        "retrieval_query": "saphenous vein thrombosis superficial vein thrombosis lower limb",
                        "scope": "multi_guideline",
                        "confirmation_message": "gate message",
                    },
                    "retrieval_payload": {
                        "result": "stored retrieval",
                        "llm_citation_chunks": [{"text": "x", "recommendation_id": "1", "class": "IIa", "level": "B", "guideline": "ESVS"}],
                        "llm_narrative_chunks": [],
                        "ui_citation_chunks": [{"text": "x", "recommendation_id": "1", "class": "IIa", "level": "B", "guideline": "ESVS"}],
                        "ui_narrative_chunks": [],
                        "citation_chunks": [{"text": "x"}],
                        "narrative_chunks": [],
                        "selected_guidelines": [
                            {"key": "venous_thrombosis", "name": "Management of Venous Thrombosis"},
                            {"key": "chronic_venous_disease", "name": "Chronic Venous Disease of the Lower Limbs"},
                        ],
                        "query_normalization": {},
                        "intent_profile": {},
                        "assets": [],
                    },
                }

            async def _call_confirmation_phase(self, question: str, history: list, pre_result: dict) -> dict:
                self.calls.append(("confirm", question, tuple(history), pre_result["retrieval_query"]))
                return {
                    "phase": "complete",
                    "reused": True,
                    "decision_reason": "clarification answered",
                }

            async def _build_response_from_payload(self, data, emitter, analysis_question: str, guidelines=None) -> str:
                self.calls.append(("build", analysis_question, tuple(guidelines or [])))
                return "final answer"

        tool = RecoveryTools()
        messages = [
            {"role": "user", "content": "Patient with saphenous thrombosis"},
            {
                "role": "assistant",
                "content": (
                    "Interpreting the clinical question before retrieval...\n"
                    "-> Understanding: Patient with thrombosis involving the saphenous vein of the lower limb\n"
                    "-> Searching: Venous Thrombosis (DVT/PE), Chronic Venous Disease\n"
                    "-> Query terms: saphenous vein thrombosis superficial lower limb\n"
                    "-> To sharpen: What is the extent and location along the saphenous vein?\n"
                    "Reply to confirm, or add details to refine the search.\n"
                    "The provided ESVS guideline context does not explicitly address this scenario."
                ),
            },
            {"role": "user", "content": "Superficial, 4cm from SFJ"},
        ]

        result = await tool.consult_vascular_guidelines(
            question="Superficial, 4cm from SFJ",
            guideline_1="venous_thrombosis",
            guideline_2="chronic_venous_disease",
            __user__={"id": "recovery-user"},
            __messages__=messages,
            __event_emitter__=None,
        )

        self.assertEqual("final answer", result)
        self.assertIn(("pre", "Patient with saphenous thrombosis", tuple(), ("venous_thrombosis", "chronic_venous_disease")), tool.calls)
        self.assertIn(
            ("confirm", "Superficial, 4cm from SFJ", ("Patient with saphenous thrombosis",), "saphenous vein thrombosis superficial vein thrombosis lower limb"),
            tool.calls,
        )
        self.assertTrue(any(call[0] == "build" for call in tool.calls))
        self.assertFalse(any(call[:2] == ("pre", "Superficial, 4cm from SFJ") for call in tool.calls))

    async def test_missing_session_recovers_pending_gate_for_rewritten_follow_up(self):
        class RecoveryTools(Tools):
            def __init__(self):
                super().__init__()
                self.calls = []

            async def _emit_status(self, emitter, description: str, done: bool = False):
                self.calls.append(("status", description, done))

            async def _call_pre_retrieval(self, question: str, history: list, guidelines: list) -> dict:
                self.calls.append(("pre", question, tuple(history), tuple(guidelines)))
                return {
                    "phase": "awaiting_confirmation",
                    "confirmation_message": "gate message",
                    "pre_retrieval_result": {
                        "proceed": True,
                        "soft_warn": True,
                        "clarification_questions": [
                            "What is the patient's haemodynamic stability?"
                        ],
                        "provisional_diagnosis": "Acute non-A non-B aortic dissection just above the left subclavian artery with carotid extension, carotid thrombus, and ischemic stroke",
                        "guidelines": ["descending_thoracic_aorta", "aortic_arch", "carotid_vertebral"],
                        "retrieval_query": "non a non b dissection thoracic aorta aortic arch left subclavian carotid extension carotid thrombus",
                        "scope": "multi_guideline",
                        "confirmation_message": "gate message",
                    },
                    "retrieval_payload": {
                        "result": "stored retrieval",
                        "llm_citation_chunks": [{"text": "x", "recommendation_id": "1", "class": "IIa", "level": "B", "guideline": "ESVS"}],
                        "llm_narrative_chunks": [],
                        "ui_citation_chunks": [{"text": "x", "recommendation_id": "1", "class": "IIa", "level": "B", "guideline": "ESVS"}],
                        "ui_narrative_chunks": [],
                        "citation_chunks": [{"text": "x"}],
                        "narrative_chunks": [],
                        "selected_guidelines": [
                            {"key": "descending_thoracic_aorta", "name": "Management of Descending Thoracic and Thoraco-Abdominal Aortic Diseases"},
                            {"key": "aortic_arch", "name": "Treatment of Thoracic Aortic Pathologies Involving the Aortic Arch"},
                            {"key": "carotid_vertebral", "name": "Management of Atherosclerotic Carotid and Vertebral Artery Disease"},
                        ],
                        "query_normalization": {},
                        "intent_profile": {},
                        "assets": [],
                    },
                }

            async def _call_confirmation_phase(self, question: str, history: list, pre_result: dict) -> dict:
                self.calls.append(("confirm", question, tuple(history), pre_result["retrieval_query"]))
                return {
                    "phase": "complete",
                    "reused": True,
                    "decision_reason": "clarification answered",
                }

            async def _build_response_from_payload(self, data, emitter, analysis_question: str, guidelines=None) -> str:
                self.calls.append(("build", analysis_question, tuple(guidelines or [])))
                return "final answer"

        tool = RecoveryTools()
        messages = [
            {"role": "user", "content": "My patient has a dissection just above the left subclavian and also dissected the carotid with thrombus and stroke."},
            {
                "role": "assistant",
                "content": (
                    "Interpreting the clinical question before retrieval...\n"
                    "-> Understanding: Acute non-A non-B aortic dissection just above the left subclavian artery with carotid extension, carotid thrombus, and ischemic stroke\n"
                    "-> Searching: Thoracic Aorta, Aortic Arch, Carotid & Vertebral\n"
                    "-> Query terms: non-A non-B dissection, thoracic aorta, aortic arch, left subclavian, carotid extension, carotid thrombus\n"
                    "-> To sharpen: What is the patient's haemodynamic stability?\n"
                    "Reply to confirm, or add details to refine the search.\n"
                    "The provided ESVS guideline context does not explicitly address this scenario."
                ),
            },
            {"role": "user", "content": "stable, minor stroke, local"},
        ]

        rewritten = (
            "Stable patient with acute non-A non-B aortic dissection just above the left subclavian artery, "
            "with carotid extension, carotid thrombus, and minor stroke — what does ESVS recommend "
            "regarding surgical or endovascular intervention?"
        )
        result = await tool.consult_vascular_guidelines(
            question=rewritten,
            guideline_1="descending_thoracic_aorta",
            guideline_2="carotid_vertebral",
            __user__={"id": "rewrite-recovery-user"},
            __messages__=messages,
            __event_emitter__=None,
        )

        self.assertEqual("final answer", result)
        self.assertIn(
            ("pre", "My patient has a dissection just above the left subclavian and also dissected the carotid with thrombus and stroke.", tuple(), ("descending_thoracic_aorta", "carotid_vertebral")),
            tool.calls,
        )
        self.assertIn(
            ("confirm", rewritten, ("My patient has a dissection just above the left subclavian and also dissected the carotid with thrombus and stroke.",), "non a non b dissection thoracic aorta aortic arch left subclavian carotid extension carotid thrombus"),
            tool.calls,
        )
        self.assertFalse(any(call[:2] == ("pre", rewritten) for call in tool.calls))

    async def test_existing_session_is_not_cleared_for_rewritten_follow_up_after_gate(self):
        class SessionTools(Tools):
            def __init__(self):
                super().__init__()
                self.calls = []

            async def _emit_status(self, emitter, description: str, done: bool = False):
                self.calls.append(("status", description, done))

            async def _call_pre_retrieval(self, question: str, history: list, guidelines: list) -> dict:
                self.calls.append(("pre", question, tuple(history), tuple(guidelines)))
                return {
                    "phase": "pre_retrieval",
                    "confirmation_message": "should not be used",
                    "pre_retrieval_result": {},
                }

            async def _call_confirmation_phase(self, question: str, history: list, pre_result: dict) -> dict:
                self.calls.append(("confirm", question, tuple(history), pre_result["retrieval_query"]))
                return {"phase": "complete", "reused": True}

            async def _await_session_payload(self, user_id: str, session: dict, emitter) -> dict:
                self.calls.append(("await", user_id))
                return {
                    "result": "retrieved",
                    "llm_citation_chunks": [],
                    "llm_narrative_chunks": [],
                    "ui_citation_chunks": [],
                    "ui_narrative_chunks": [],
                    "citation_chunks": [],
                    "narrative_chunks": [],
                    "selected_guidelines": [
                        {"key": "descending_thoracic_aorta", "name": "Management of Descending Thoracic and Thoraco-Abdominal Aortic Diseases"},
                        {"key": "aortic_arch", "name": "Treatment of Thoracic Aortic Pathologies Involving the Aortic Arch"},
                    ],
                    "query_normalization": {},
                    "intent_profile": {},
                    "assets": [],
                }

            async def _build_response_from_payload(self, data, emitter, analysis_question: str, guidelines=None) -> str:
                self.calls.append(("build", analysis_question, tuple(guidelines or [])))
                return "final answer"

        tool = SessionTools()
        tool._store_session(
            "user:session-user",
            payload=None,
            pre_result={
                "proceed": True,
                "soft_warn": True,
                "clarification_questions": ["What is the patient's haemodynamic stability?"],
                "provisional_diagnosis": "Acute non-A non-B aortic dissection just above the left subclavian artery with carotid extension, carotid thrombus, and ischemic stroke",
                "guidelines": ["descending_thoracic_aorta", "aortic_arch", "carotid_vertebral"],
                "retrieval_query": "non a non b dissection thoracic aorta aortic arch left subclavian carotid extension carotid thrombus",
                "scope": "multi_guideline",
                "confirmation_message": "gate message",
            },
            task=None,
        )
        messages = [
            {"role": "user", "content": "My patient has a dissection just above the left subclavian and also dissected the carotid with thrombus and stroke."},
            {
                "role": "assistant",
                "content": (
                    "Interpreting the clinical question before retrieval...\n"
                    "-> Understanding: Acute non-A non-B aortic dissection just above the left subclavian artery with carotid extension, carotid thrombus, and ischemic stroke\n"
                    "-> Searching: Thoracic Aorta, Aortic Arch, Carotid & Vertebral\n"
                    "-> Query terms: non-A non-B dissection, thoracic aorta, aortic arch, left subclavian, carotid extension, carotid thrombus\n"
                    "-> To sharpen: What is the patient's haemodynamic stability?\n"
                    "Reply to confirm, or add details to refine the search.\n"
                    "The provided ESVS guideline context does not explicitly address this scenario."
                ),
            },
            {"role": "user", "content": "stable, minor stroke, local"},
        ]
        rewritten = (
            "Stable patient with acute non-A non-B aortic dissection just above the left subclavian artery, "
            "with carotid extension, carotid thrombus, and minor stroke — what does ESVS recommend "
            "regarding surgical or endovascular intervention?"
        )

        result = await tool.consult_vascular_guidelines(
            question=rewritten,
            guideline_1="descending_thoracic_aorta",
            guideline_2="carotid_vertebral",
            __user__={"id": "session-user"},
            __messages__=messages,
            __event_emitter__=None,
        )

        self.assertEqual("final answer", result)
        self.assertTrue(any(call[0] == "confirm" for call in tool.calls))
        self.assertTrue(any(call[0] == "await" for call in tool.calls))
        self.assertFalse(any(call[0] == "pre" for call in tool.calls))

    async def test_new_phase1_returns_gate_wrapper_for_model(self):
        class PhaseOneTools(Tools):
            async def _emit_status(self, emitter, description: str, done: bool = False):
                return None

            async def _call_pre_retrieval(self, question: str, history: list, guidelines: list) -> dict:
                return {
                    "phase": "awaiting_confirmation",
                    "confirmation_message": (
                        "-> Understanding: Symptomatic carotid stenosis with recent transient ischemic attack\n"
                        "-> Searching: Carotid & Vertebral\n"
                        "-> Query terms: symptomatic carotid stenosis transient ischemic attack\n"
                        "Reply to confirm, or add details to refine the search."
                    ),
                    "pre_retrieval_result": {
                        "proceed": True,
                        "soft_warn": False,
                        "clarification_questions": [],
                        "provisional_diagnosis": "Symptomatic carotid stenosis with recent transient ischemic attack",
                        "guidelines": ["carotid_vertebral"],
                        "retrieval_query": "symptomatic carotid stenosis transient ischemic attack",
                        "scope": "single_guideline",
                        "confirmation_message": (
                            "-> Understanding: Symptomatic carotid stenosis with recent transient ischemic attack\n"
                            "-> Searching: Carotid & Vertebral\n"
                            "-> Query terms: symptomatic carotid stenosis transient ischemic attack\n"
                            "Reply to confirm, or add details to refine the search."
                        ),
                    },
                    "retrieval_payload": {"result": "stored"},
                }

        tool = PhaseOneTools()
        result = await tool.consult_vascular_guidelines(
            question="Patient with symptomatic carotid stenosis after TIA",
            guideline_1="carotid_vertebral",
            __user__={"id": "phase1-user"},
            __messages__=[{"role": "user", "content": "Patient with symptomatic carotid stenosis after TIA"}],
            __event_emitter__=None,
        )

        self.assertIn("GUIDELINE_RETRIEVAL_PAUSED", result)
        self.assertIn("USER-FACING CLARIFICATION MESSAGE (copy exactly):", result)
        self.assertIn("-> Understanding: Symptomatic carotid stenosis with recent transient ischemic attack", result)

    async def test_phase1_does_not_emit_gate_text_as_status_message(self):
        class PhaseOneTools(Tools):
            async def _call_pre_retrieval(self, question: str, history: list, guidelines: list) -> dict:
                return {
                    "phase": "awaiting_confirmation",
                    "confirmation_message": (
                        "-> Understanding: Symptomatic carotid stenosis with recent transient ischemic attack\n"
                        "-> Searching: Carotid & Vertebral\n"
                        "-> Query terms: symptomatic carotid stenosis transient ischemic attack\n"
                        "Reply to confirm, or add details to refine the search."
                    ),
                    "pre_retrieval_result": {
                        "proceed": True,
                        "soft_warn": False,
                        "clarification_questions": [],
                        "provisional_diagnosis": "Symptomatic carotid stenosis with recent transient ischemic attack",
                        "guidelines": ["carotid_vertebral"],
                        "retrieval_query": "symptomatic carotid stenosis transient ischemic attack",
                        "scope": "single_guideline",
                        "confirmation_message": (
                            "-> Understanding: Symptomatic carotid stenosis with recent transient ischemic attack\n"
                            "-> Searching: Carotid & Vertebral\n"
                            "-> Query terms: symptomatic carotid stenosis transient ischemic attack\n"
                            "Reply to confirm, or add details to refine the search."
                        ),
                    },
                }

            async def _run_retrieval_task(self, question: str, history: list, guidelines: list) -> dict:
                return {"result": "stored"}

        tool = PhaseOneTools()
        events = []

        async def emitter(event):
            events.append(event)

        result = await tool.consult_vascular_guidelines(
            question="Patient with symptomatic carotid stenosis after TIA",
            guideline_1="carotid_vertebral",
            __user__={"id": "phase1-user"},
            __metadata__={"chat_id": "phase1-chat"},
            __messages__=[{"role": "user", "content": "Patient with symptomatic carotid stenosis after TIA"}],
            __event_emitter__=emitter,
        )

        message_texts = [
            str((event.get("data") or {}).get("content") or "")
            for event in events
            if event.get("type") == "message"
        ]
        self.assertTrue(any("Interpreting the clinical question before retrieval..." in text for text in message_texts))
        self.assertFalse(any("-> Understanding:" in text or "🩺 Understanding" in text for text in message_texts))
        self.assertIn("GUIDELINE_RETRIEVAL_PAUSED", result)


if __name__ == "__main__":
    unittest.main()
