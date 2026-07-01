"""Enigma NLP microservice.

Stateless analysis service. Laravel sends normalized posts; this service returns
narratives (clusters), repetition groups, the author co-action graph, and scored
coordination clusters — each with evidence attached. Nothing is persisted here.
"""
__version__ = "0.1.0"
